<?php

namespace App\Http\Controllers\Admin\Event;

use App\Http\Controllers\Controller;
use App\Models\EventParticipant;
use App\Services\AdminLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Showing one side of a competitor's identity card.
 *
 * The only way these images can be read, and the reason they are stored off the published
 * disk. A logo sits on the public disk and is handed out by the web server to anybody who
 * can work out the URL, which is fine for something meant to be seen. An identity document
 * is not, and no filename is obscure enough to be treated as access control.
 *
 * So the file is streamed by the application, after checking three things in order: that
 * the operator holds the permission, that the participant exists, and that the requested
 * side was actually uploaded. Anything else is a 404 rather than an explanation.
 *
 * Every view is logged. Somebody looking at a stranger's identity card should leave a
 * record of having done so, and an audit that only covers changes would not have one.
 */
class IdentityCardController extends Controller
{
    /**
     * The two sides, mapped to the columns that hold them.
     *
     * A whitelist rather than interpolating the side into a column name, so the route
     * parameter can never reach the database as anything other than one of these two.
     */
    private const SIDES = [
        'front' => 'ic_front_path',
        'back' => 'ic_back_path',
    ];

    public function show(Request $request, EventParticipant $participant, string $side): StreamedResponse
    {
        $column = self::SIDES[$side] ?? null;

        if ($column === null) {
            throw new NotFoundHttpException();
        }

        $path = $participant->{$column};

        if (blank($path)) {
            throw new NotFoundHttpException();
        }

        /*
         | The private disk, named here rather than taken from the stored value.
         |
         | The column holds a relative path, so a row that somehow carried something like
         | ../../.env could otherwise be used to read a file outside the directory. Asking
         | the disk for it keeps the read inside the disk's own root.
         */
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            throw new NotFoundHttpException();
        }

        AdminLogger::activity(
            'participants.ic.view',
            sprintf('Viewed the %s of %s\'s identity card.', $side, $participant->full_name),
        );

        return $disk->response(
            $path,
            // A name for the download dialog that says what it is without carrying the
            // competitor's own filename, which is often their name or card number.
            sprintf('ic-%s-%d.jpg', $side, $participant->id),
            [
                // Shown in the page rather than downloaded, so reading one does not leave
                // a copy in the operator's Downloads folder by default.
                'Content-Disposition' => 'inline',

                // Nothing between here and the browser may keep a copy, and no history
                // entry should be able to serve it again after sign out.
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'Pragma' => 'no-cache',
                'X-Robots-Tag' => 'noindex, nofollow, noarchive',

                // The file has already been checked to be an image by validation, but a
                // browser sniffing a different type out of it is how an upload becomes a
                // script that runs on this domain.
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
