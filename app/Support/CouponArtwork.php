<?php

namespace App\Support;

use App\Models\Coupon;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * One coupon as a standalone SVG file, for a poster, a print shop or a phone.
 *
 * The web designs are HTML and Tailwind, and they cannot be downloaded: a file saved
 * off this site has to open on a machine that has never seen it, with no stylesheet,
 * no web font and no remote image behind it. So the five designs are drawn again here
 * as real SVG, lifted from the artwork the owner approved, and everything that file
 * had hardcoded is read off the coupon instead.
 *
 * WHY THE MEASURING IS HERE
 *
 * SVG text does not wrap. There is no layout engine in a file, no flexbox and no
 * break-words, so "10TH ANNIVERSARY UNDERGROUND RUNNERS SIBU FUN RUN" dropped into a
 * <text> runs straight out of the card and off the page. Nor can the server measure a
 * string: the font that renders it is whatever the machine opening the file happens to
 * have. What it CAN do is budget — every slot in SLOTS carries the width it occupies
 * in the artwork, the size its type is set at, and how many characters that buys at an
 * average advance. A value past its budget is stepped down a size, and past the floor
 * it is cut on a word boundary with an ellipsis.
 *
 * The ratios are deliberately generous rather than exact. Overestimating a character's
 * width wraps a line one word early; underestimating it puts the end of an event title
 * outside the card, which is the failure that cannot be fixed after the file has been
 * sent to a printer.
 */
final class CouponArtwork
{
    /** Drawn when a stored design names a template that is not there. */
    public const FALLBACK = 'classic';

    /** The canvas: the 640x200 card from the artwork, with a 40px margin round it. */
    public const WIDTH = 720;
    public const HEIGHT = 280;

    /**
     * Average advance per character, as a fraction of the font size.
     *
     * Three figures rather than one, because the same 14px buys very different widths:
     * mixed-case prose is narrow, an uppercase line is wider, and a monospace code is
     * every character at the widest one's width.
     */
    private const SANS = 0.52;
    private const CAPS = 0.62;
    private const MONO = 0.62;

    /**
     * What each design has room for, per slot.
     *
     *   width    how many user units the slot spans in the drawing
     *   size     the font size the artwork sets it at
     *   min      how far it may be stepped down before text is cut instead
     *   ratio    which of the three advances above applies
     *   spacing  letter-spacing, which adds to every character's advance
     *   lines    how many lines the slot has room for
     *
     * A slot with no `min` never shrinks: a line of prose wraps instead, and a slot
     * with no `lines` is a single line that steps down and then truncates.
     *
     * The code slots floor at 8.5 for one specific reason. A coupon name may be 32
     * characters, and the chip it prints in is under 190 units wide, so the only way a
     * long code fits is small type with the tracking dropped. A TRUNCATED CODE IS NOT
     * A COUPON — it is a string that fails at redemption — so these are sized to hold
     * the longest name validation allows rather than to look tidy.
     */
    private const SLOTS = [
        'classic' => [
            'eyebrow' => ['width' => 400, 'size' => 11, 'ratio' => self::CAPS, 'spacing' => 1.6],
            'discount' => ['width' => 412, 'size' => 54, 'min' => 26, 'ratio' => self::CAPS],
            'subject' => ['width' => 412, 'size' => 14, 'lines' => 2],
            'expiry' => ['width' => 412, 'size' => 12.5],
            'code' => ['width' => 176, 'size' => 23, 'min' => 8.5, 'ratio' => self::MONO, 'spacing' => 2],
        ],

        'bold' => [
            'eyebrow' => ['width' => 360, 'size' => 11, 'ratio' => self::CAPS, 'spacing' => 1.6],

            // The hero block is 200 units wide and sized for one short number, which
            // is why it steps down hardest: "50" fills it, "1,250.00" does not.
            'hero' => ['width' => 176, 'size' => 62, 'min' => 18, 'ratio' => self::CAPS],
            'unit' => ['width' => 176, 'size' => 22, 'min' => 12, 'ratio' => self::CAPS],

            'subject' => ['width' => 366, 'size' => 18, 'lines' => 2],
            'code' => ['width' => 178, 'size' => 20, 'min' => 8.5, 'ratio' => self::MONO, 'spacing' => 2],

            // Beside the code chip, so it has the narrowest expiry of the five and
            // shrinks rather than running under it.
            'expiry' => ['width' => 160, 'size' => 12.5, 'min' => 8.5],
        ],

        'minimal' => [
            'discount' => ['width' => 380, 'size' => 40, 'min' => 20],
            'subject' => ['width' => 350, 'size' => 13, 'lines' => 2],
            'code' => ['width' => 190, 'size' => 26, 'min' => 8.5, 'ratio' => self::MONO, 'spacing' => 5],
            'expiry' => ['width' => 220, 'size' => 12.5],
        ],

        'stamp' => [
            'eyebrow' => ['width' => 356, 'size' => 11, 'ratio' => self::CAPS, 'spacing' => 1.6],
            'discount' => ['width' => 356, 'size' => 42, 'min' => 22, 'ratio' => self::CAPS],
            'subject' => ['width' => 356, 'size' => 13.5, 'lines' => 2],
            'expiry' => ['width' => 356, 'size' => 12.5],
            'code' => ['width' => 178, 'size' => 25, 'min' => 8.5, 'ratio' => self::MONO, 'spacing' => 2],
        ],

        'gradient' => [
            'eyebrow' => ['width' => 358, 'size' => 10.5, 'ratio' => self::CAPS, 'spacing' => 2],
            'discount' => ['width' => 358, 'size' => 48, 'min' => 24, 'ratio' => self::CAPS],
            'subject' => ['width' => 358, 'size' => 13.5, 'lines' => 2],
            'expiry' => ['width' => 358, 'size' => 12.5],
            'code' => ['width' => 180, 'size' => 22, 'min' => 8.5, 'ratio' => self::MONO, 'spacing' => 2],
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private array $measured = [];

    private function __construct(public readonly CouponTicket $ticket)
    {
    }

    /**
     * The artwork for a saved coupon, reading its own figures.
     *
     * The subject is what the batch is actually ticked on, because a poster has to say
     * which event the discount is for. With several things ticked it falls back to the
     * kind, the same way CouponTicket does when a caller has nothing specific: one line
     * of SVG text cannot name five events, and naming only the first would be wrong.
     */
    public static function for(Coupon $coupon): self
    {
        return new self(CouponTicket::for($coupon, self::subjectFor($coupon)));
    }

    private static function subjectFor(Coupon $coupon): ?string
    {
        $titles = $coupon->isForShop()
            ? $coupon->products()->pluck('name')
            : $coupon->events()->pluck('title');

        return $titles->count() === 1 ? (string) $titles->first() : null;
    }

    /* ---------------------------------------------------------------------
     | The file
     * ------------------------------------------------------------------ */

    /**
     * The design being drawn, which is never 'custom'.
     *
     * Custom artwork is an uploaded raster image and is served as itself; nothing here
     * wraps a photograph in an SVG shell and calls it vector.
     */
    public function design(): string
    {
        $key = (string) $this->ticket->coupon->design;

        return array_key_exists($key, self::SLOTS) ? $key : self::FALLBACK;
    }

    /**
     * The whole file, prolog included.
     *
     * The declaration is written here rather than in the template because `<?xml` at
     * the top of a Blade file is a PHP short open tag on any installation that has
     * them switched on.
     */
    public function svg(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . trim(View::make('coupon.svg.artwork', ['art' => $this])->render()) . "\n";
    }

    public function filename(): string
    {
        return sprintf('coupon-%s-%s.svg', $this->ticket->code, $this->design());
    }

    /** Spelt out for anything that reads the file rather than looking at it. */
    public function summary(): string
    {
        return $this->ticket->summary();
    }

    /* ---------------------------------------------------------------------
     | What goes in a slot
     * ------------------------------------------------------------------ */

    /** The font size a slot is drawn at, after any step down. */
    public function size(string $slot): float
    {
        return $this->metrics($slot)['size'];
    }

    /** Its letter-spacing, which is the first thing dropped when type shrinks. */
    public function spacing(string $slot): float
    {
        return $this->metrics($slot)['spacing'];
    }

    /** A one-line slot, cut with an ellipsis if it is still past its budget. */
    public function line(string $slot): string
    {
        $text = $this->value($slot);
        $capacity = $this->metrics($slot)['capacity'];

        return mb_strlen($text) <= $capacity
            ? $text
            : Str::limit($text, max(1, $capacity - 1), '…');
    }

    /**
     * A slot with room for more than one line, split on word boundaries.
     *
     * wordwrap() rather than a hand-rolled splitter, with its cut flag on so a single
     * word longer than the line is broken instead of overflowing. It counts bytes, so
     * an accented title wraps slightly early — the safe direction, and the reason the
     * budgets are not tightened further.
     *
     * @return array<int, string>
     */
    public function lines(string $slot): array
    {
        $metrics = $this->metrics($slot);
        $capacity = $metrics['capacity'];

        $wrapped = explode("\n", wordwrap(trim($this->value($slot)), $capacity, "\n", true));
        $kept = array_slice($wrapped, 0, $metrics['lines']);

        // More text than lines: the last one says so rather than stopping mid-title.
        if (count($wrapped) > count($kept) && $kept !== []) {
            $last = (string) end($kept);
            $kept[count($kept) - 1] = mb_substr($last, 0, max(1, $capacity - 1)) . '…';
        }

        return array_values(array_filter($kept, fn (string $line): bool => $line !== ''));
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * The four facts, plus the two labels that carry them.
     *
     * Read off CouponTicket so the file says exactly what the web design says. A
     * downloaded coupon that disagreed with the one on the screen would be the worse
     * of the two bugs: the code is typed off whichever the holder is looking at.
     */
    private function value(string $slot): string
    {
        return match ($slot) {
            'eyebrow' => $this->ticket->kindLabel() . ' COUPON',
            'discount' => $this->ticket->discountLabel(),
            'hero' => $this->ticket->heroValue(),
            'unit' => $this->ticket->heroUnit(),
            'subject' => $this->ticket->subject,
            'expiry' => $this->ticket->expiryNote(),
            'code' => $this->ticket->code,
            default => '',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function metrics(string $slot): array
    {
        return $this->measured[$slot] ??= $this->measure($slot);
    }

    /**
     * @return array<string, mixed>
     */
    private function measure(string $slot): array
    {
        $spec = $this->spec($slot);
        $length = max(1, mb_strlen($this->value($slot)));

        /*
         | The largest size whose advance fits the slot, never above what the artwork
         | sets and never below the floor that keeps it readable.
         |
         | Rounded DOWN to a tenth, and that is not tidying. A size rounded UP needs
         | marginally more width than the slot has, so the budget worked out from it
         | comes back one character short of the very value that produced it — which
         | is how "RM 1,250.50" lost its last digits to an ellipsis instead of simply
         | being set smaller. Rounding down keeps advance * length <= width, so a slot
         | that shrank to fit never then truncates.
         */
        $fits = (($spec['width'] / $length) - $spec['spacing']) / $spec['ratio'];
        $size = floor(max($spec['min'], min($spec['size'], $fits)) * 10) / 10;

        // Tracking goes first. It is what makes a six character code look typeset, and
        // it is pure cost on a long one that is already fighting for room.
        $spacing = $size < $spec['size'] ? 0.0 : (float) $spec['spacing'];

        return [
            'size' => $size,
            'spacing' => $spacing,
            'capacity' => max(1, (int) floor($spec['width'] / ($size * $spec['ratio'] + $spacing))),
            'lines' => max(1, (int) $spec['lines']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(string $slot): array
    {
        $slots = self::SLOTS[$this->design()];

        if (! array_key_exists($slot, $slots)) {
            throw new \InvalidArgumentException(sprintf(
                'The %s coupon design has no %s slot.',
                $this->design(),
                $slot,
            ));
        }

        return $slots[$slot] + [
            'ratio' => self::SANS,
            'spacing' => 0.0,
            'lines' => 1,

            // No floor given means the slot does not shrink: it wraps or it truncates.
            'min' => $slots[$slot]['size'],
        ];
    }
}
