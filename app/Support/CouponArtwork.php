<?php

namespace App\Support;

use App\Models\Coupon;
use Illuminate\Support\Facades\View;

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
 * <text> runs straight out of the card, or straight under the code chip, and nobody
 * notices until it has been printed. Nor can the server measure a string: the font
 * that renders it is whatever the machine opening the file happens to have. What it
 * CAN do is estimate — ADVANCE gives the width of a character as a fraction of the
 * font size, so a whole string has an estimated width, and a slot has a width it is
 * allowed to occupy. Too wide is set smaller, wrapped onto a second line, and only
 * then cut.
 *
 * WHERE A SLOT'S WIDTH COMES FROM
 *
 * Not from the card, and not from a number somebody typed. Every design puts the code
 * on the right, so the title is bounded by GEOMETRY: where the left column's text
 * starts, where the code block begins, and the gutter the two keep between them. A
 * slot in that column asks for no width of its own and is handed what is actually
 * free. Measuring the title against the full card is what let it run under the chip.
 *
 * The estimate is deliberately generous rather than exact. Overestimating a
 * character's width wraps a line one word early; underestimating it puts the end of
 * an event title on top of the code, which is the failure that cannot be fixed after
 * the file has been sent to a printer.
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
     * Measured off the stack the file names, with Segoe UI first, and rounded up. The
     * split matters because the same 13.5px buys very different widths: an uppercase
     * event title is a fifth wider than the same number of lowercase letters, and
     * measuring one as the other is exactly how a 49 character title that was
     * budgeted to fit drew 30 units past the code chip.
     */
    private const ADVANCE = [
        'caps' => 0.64,
        'lower' => 0.52,
        'digit' => 0.57,
        'space' => 0.30,
        'narrow' => 0.36,
        'wide' => 0.90,
    ];

    /** The thin ones, which an all-caps ratio would charge far too much for. */
    private const NARROW = "iljtfI.,;:'!|";

    /** And the ones no average covers. */
    private const WIDE = 'MWmw%@&';

    /** A monospace code is every character at the widest one's width. */
    private const MONO = 0.62;

    /**
     * What the left column of each design owns, read off the artwork.
     *
     *   text    the x the column's text starts at
     *   code    the x the code block begins at, which the column may not reach
     *   gutter  the space kept between the two
     *
     * `code` is the real edge of the thing on the right, not the card's:
     *
     *   classic   the perforation, at which the stub starts
     *   bold      the card's own right inset — the chip sits BELOW the title here,
     *             so nothing shares the title's line
     *   minimal   where the right-hand column starts: both of its slots are anchored
     *             to x=640 and the wider of the two, the expiry, is 220 across
     *   stamp     the leftmost corner the rotated stamp box reaches
     *   gradient  the frosted chip
     *
     * The gutter is 28 across the board, which is roughly two characters of a title.
     * It has to be worth something: the estimate below is within a few per cent of
     * what a line really draws, and on a gutter of one character's width a few per
     * cent is the difference between clearing the chip and touching it.
     */
    private const GEOMETRY = [
        'classic' => ['text' => 78, 'code' => 498, 'gutter' => 28],
        'bold' => ['text' => 286, 'code' => 680, 'gutter' => 28],
        'minimal' => ['text' => 80, 'code' => 420, 'gutter' => 28],
        'stamp' => ['text' => 78, 'code' => 449, 'gutter' => 28],
        'gradient' => ['text' => 78, 'code' => 456, 'gutter' => 28],
    ];

    /**
     * What each design has room for, per slot.
     *
     *   size     the font size the artwork sets it at
     *   min      how far it may be stepped down before text is cut instead
     *   spacing  letter-spacing, which adds to every character's advance
     *   lines    how many lines the slot has room for
     *   width    only where the slot is NOT the left column: the code chip, bold's
     *            hero block, the right-hand expiry. Everything else is handed the
     *            width GEOMETRY leaves free.
     *   mono     a monospace code, measured at one flat advance
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
            'eyebrow' => ['size' => 11, 'spacing' => 1.6],
            'discount' => ['size' => 54, 'min' => 26],
            'subject' => ['size' => 14, 'lines' => 2],
            'expiry' => ['size' => 12.5],
            'code' => ['width' => 176, 'size' => 23, 'min' => 8.5, 'mono' => true, 'spacing' => 2],
        ],

        'bold' => [
            'eyebrow' => ['size' => 11, 'spacing' => 1.6],

            // The hero block is 200 units wide and sized for one short number, which
            // is why it steps down hardest: "50" fills it, "1,250.00" does not.
            'hero' => ['width' => 176, 'size' => 62, 'min' => 18],
            'unit' => ['width' => 176, 'size' => 22, 'min' => 12],

            'subject' => ['size' => 18, 'lines' => 2],
            'code' => ['width' => 178, 'size' => 20, 'min' => 8.5, 'mono' => true, 'spacing' => 2],

            // Beside the code chip, so it has the narrowest expiry of the five and
            // shrinks rather than running under it.
            'expiry' => ['width' => 160, 'size' => 12.5, 'min' => 8.5],
        ],

        'minimal' => [
            'discount' => ['size' => 40, 'min' => 20],
            'subject' => ['size' => 13, 'lines' => 2],
            'code' => ['width' => 190, 'size' => 26, 'min' => 8.5, 'mono' => true, 'spacing' => 5],
            'expiry' => ['width' => 220, 'size' => 12.5, 'min' => 8.5],
        ],

        'stamp' => [
            'eyebrow' => ['size' => 11, 'spacing' => 1.6],
            'discount' => ['size' => 42, 'min' => 22],
            'subject' => ['size' => 13.5, 'lines' => 2],
            'expiry' => ['size' => 12.5],
            'code' => ['width' => 178, 'size' => 25, 'min' => 8.5, 'mono' => true, 'spacing' => 2],
        ],

        'gradient' => [
            'eyebrow' => ['size' => 10.5, 'spacing' => 2],
            'discount' => ['size' => 48, 'min' => 24],
            'subject' => ['size' => 13.5, 'lines' => 2],
            'expiry' => ['size' => 12.5],
            'code' => ['width' => 180, 'size' => 22, 'min' => 8.5, 'mono' => true, 'spacing' => 2],
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private array $measured = [];

    private function __construct(public readonly CouponTicket $ticket) {}

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
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .trim(View::make('coupon.svg.artwork', ['art' => $this])->render())."\n";
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

    /** A one-line slot, cut with an ellipsis if it is still past its width. */
    public function line(string $slot): string
    {
        return $this->fit($this->value($slot), $this->metrics($slot));
    }

    /**
     * A slot with room for more than one line, split on word boundaries.
     *
     * Wrapped by measured width rather than by a character count, because a count
     * cannot tell "RUNNERS SIBU" from "runners sibu" and the caps line is a fifth
     * wider. Never broken through the middle of a word: a word too long for the line
     * is cut with an ellipsis, which says it was cut, instead of silently continuing
     * on the next line as if the title had a space in it.
     *
     * @return array<int, string>
     */
    public function lines(string $slot): array
    {
        $metrics = $this->metrics($slot);
        $words = preg_split('/\s+/', trim($this->value($slot)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($current !== '' && ! $this->fits($candidate, $metrics)) {
                $lines[] = $current;
                $current = $word;

                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        $kept = array_slice($lines, 0, $metrics['lines']);

        // More title than lines: the last one says so rather than stopping dead.
        if (count($lines) > count($kept) && $kept !== []) {
            $kept[count($kept) - 1] = $this->truncate((string) end($kept), $metrics);
        }

        // And one word too long for the line is cut rather than left to overflow.
        return array_values(array_map(fn (string $line): string => $this->fit($line, $metrics), $kept));
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
            'eyebrow' => $this->ticket->kindLabel().' COUPON',
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
     * The estimated width of a string, in user units.
     *
     * @param  array<string, mixed>  $metrics
     */
    private function widthOf(string $text, array $metrics): float
    {
        return $this->advance($text, (bool) $metrics['mono']) * $metrics['size']
            + $metrics['spacing'] * mb_strlen($text);
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function fits(string $text, array $metrics): bool
    {
        // A hundredth of a unit of slack, for the one case the arithmetic cannot win:
        // a size solved to exactly the slot's width and then rounded to a tenth comes
        // back equal to it, give or take the last bit of a float.
        return $this->widthOf($text, $metrics) <= $metrics['width'] + 0.01;
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function fit(string $text, array $metrics): string
    {
        return $this->fits($text, $metrics) ? $text : $this->truncate($text, $metrics);
    }

    /**
     * As much of a string as the slot holds, with an ellipsis for the rest.
     *
     * Three attempts, in the order that loses the least. The ellipsis on its own,
     * where the text already fits and all it has to say is that there is more. Then
     * whole words, so a cut event title does not end in half a word. Only a single
     * word too long for the slot on its own is cut through the middle, because the
     * alternative there is an ellipsis and nothing else.
     *
     * Measured at each step against the same estimate the rest of the class uses,
     * rather than at a character count, so a caps title loses as many characters as
     * its own width costs and no more.
     *
     * @param  array<string, mixed>  $metrics
     */
    private function truncate(string $text, array $metrics): string
    {
        if ($this->fits($text.'…', $metrics)) {
            return $text.'…';
        }

        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        for ($keep = count($words) - 1; $keep >= 1; $keep--) {
            $candidate = implode(' ', array_slice($words, 0, $keep)).'…';

            if ($this->fits($candidate, $metrics)) {
                return $candidate;
            }
        }

        $length = mb_strlen($text);

        while ($length > 1) {
            $candidate = rtrim(mb_substr($text, 0, --$length)).'…';

            if ($this->fits($candidate, $metrics)) {
                return $candidate;
            }
        }

        return mb_substr($text, 0, 1).'…';
    }

    /**
     * How wide a string is in multiples of its font size.
     *
     * A monospace code is every character at one width. Everything else is read
     * character by character: the project's event titles are typed in capitals as
     * often as not, and one average for both is wrong in whichever direction it is
     * set.
     */
    private function advance(string $text, bool $mono): float
    {
        $characters = mb_str_split($text);

        if ($mono) {
            return count($characters) * self::MONO;
        }

        $total = 0.0;

        foreach ($characters as $character) {
            $total += match (true) {
                $character === ' ' => self::ADVANCE['space'],
                mb_strpos(self::WIDE, $character) !== false => self::ADVANCE['wide'],
                mb_strpos(self::NARROW, $character) !== false => self::ADVANCE['narrow'],
                $character >= '0' && $character <= '9' => self::ADVANCE['digit'],
                mb_strtolower($character) !== $character => self::ADVANCE['caps'],
                default => self::ADVANCE['lower'],
            };
        }

        return $total;
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
        $value = $this->value($slot);
        $mono = (bool) $spec['mono'];
        $advance = max(0.01, $this->advance($value, $mono));
        $length = max(1, mb_strlen($value));

        /*
         | The largest size whose estimated width fits the slot, never above what the
         | artwork sets and never below the floor that keeps it readable.
         |
         | Rounded DOWN to a tenth, which is not tidying. A size rounded UP needs
         | marginally more width than the slot has, so the very value that produced it
         | comes back too wide and is then truncated — which is how "RM 1,250.50" lost
         | its last digits to an ellipsis instead of simply being set smaller.
         */
        $fit = fn (float $spacing): float => floor(
            max($spec['min'], min($spec['size'], ($spec['width'] - $spacing * $length) / $advance)) * 10
        ) / 10;

        $size = $fit((float) $spec['spacing']);

        /*
         | Tracking goes first. It is what makes a six character code look typeset, and
         | it is pure cost on a long one that is already fighting for room — so once
         | the type has had to shrink at all, the spacing is dropped and the size
         | worked out again with that room given back.
         */
        $spacing = $size < $spec['size'] ? 0.0 : (float) $spec['spacing'];

        return [
            'size' => $spacing === 0.0 ? $fit(0.0) : $size,
            'spacing' => $spacing,
            'width' => (float) $spec['width'],
            'mono' => $mono,
            'lines' => max(1, (int) $spec['lines']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(string $slot): array
    {
        $design = $this->design();
        $slots = self::SLOTS[$design];

        if (! array_key_exists($slot, $slots)) {
            throw new \InvalidArgumentException(sprintf(
                'The %s coupon design has no %s slot.',
                $design,
                $slot,
            ));
        }

        return $slots[$slot] + [
            'spacing' => 0.0,
            'lines' => 1,
            'mono' => false,

            // No width given means the slot is in the left column, and what it has is
            // whatever the code block on the right does not.
            'width' => self::freeWidth($design),

            // No floor given means the slot does not shrink: it wraps or it truncates.
            'min' => $slots[$slot]['size'],
        ];
    }

    /** The width the left column actually has, which is the whole point of this. */
    private static function freeWidth(string $design): float
    {
        $geometry = self::GEOMETRY[$design];

        return (float) ($geometry['code'] - $geometry['text'] - $geometry['gutter']);
    }
}
