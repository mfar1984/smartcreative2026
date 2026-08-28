<?php

namespace App\Http\Controllers\Admin\Portfolio;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PortfolioGalleryUploadRequest;
use App\Http\Requests\Admin\PortfolioImageRequest;
use App\Models\PortfolioImage;
use App\Models\PortfolioProject;
use App\Services\AdminLogger;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GalleryController extends Controller
{
    private const PER_PAGE = 24;

    /** Where gallery photographs live on the public disk. */
    private const IMAGE_DIRECTORY = 'portfolio-gallery';

    /**
     * The two ways of looking at the same images.
     *
     * Grid is for judging the pictures, list is for the detail around them, which is
     * why the list carries the file name, size and date and the grid does not.
     */
    public const VIEWS = [
        'grid' => ['label' => 'Grid', 'icon' => 'grid'],
        'list' => ['label' => 'List', 'icon' => 'clipboard'],
    ];

    public function index(Request $request)
    {
        $view = $this->resolveView($request->query('view'));
        $projectId = (int) $request->query('project', 0);

        $images = PortfolioImage::query()
            ->with('project')
            ->when($projectId > 0, fn (Builder $query) => $query->where('portfolio_project_id', $projectId))
            /*
             | Grouped by project, then by the order set within it, so the page reads
             | as galleries rather than as one undifferentiated pile.
             */
            ->orderBy('portfolio_project_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $projects = PortfolioProject::query()
            ->withCount('images')
            ->inDisplayOrder()
            ->get();

        return view('admin.portfolio.gallery', [
            'images' => $images,
            'projects' => $projects,
            'views' => self::VIEWS,
            'activeView' => $view,
            'projectId' => $projectId,
            'isFiltered' => $projectId > 0,
            'maxFiles' => PortfolioGalleryUploadRequest::MAX_FILES,

            /*
             | Pre-selected in the upload panel when the operator arrived from a
             | project, so the commonest path does not need the dropdown touched.
             */
            'preselectedProject' => $projectId > 0 ? $projectId : null,

            'canCreate' => $request->user()->hasPermission('portfolio.gallery.create'),
            'canUpdate' => $request->user()->hasPermission('portfolio.gallery.update'),
            'canDelete' => $request->user()->hasPermission('portfolio.gallery.delete'),
        ]);
    }

    public function store(PortfolioGalleryUploadRequest $request)
    {
        $project = PortfolioProject::query()->findOrFail($request->integer('portfolio_project_id'));

        // Continue the existing order rather than starting from zero, so a second
        // batch lands after the first instead of interleaving with it.
        $nextOrder = (int) $project->images()->max('sort_order') + 1;
        $caption = $request->input('caption');

        $count = 0;

        foreach ($request->file('images') as $file) {
            $project->images()->create([
                'path' => $file->store(self::IMAGE_DIRECTORY, 'public'),
                'caption' => $caption,
                'sort_order' => $nextOrder++,
            ]);

            $count++;
        }

        AdminLogger::activity(
            'portfolio.gallery.create',
            sprintf('Added %d %s to the %s gallery.', $count, $count === 1 ? 'photograph' : 'photographs', $project->title),
        );
        AdminLogger::audit($project, 'gallery-updated', null, [
            'title' => $project->title,
            'images_added' => $count,
        ]);

        return redirect()
            ->route('admin.portfolio.gallery', ['project' => $project->id])
            ->with('status', sprintf(
                '%d %s added to %s.',
                $count,
                $count === 1 ? 'photograph' : 'photographs',
                $project->title,
            ));
    }

    public function update(PortfolioImageRequest $request, PortfolioImage $image)
    {
        $before = [
            'project' => $image->project?->title,
            'caption' => $image->caption,
            'sort_order' => $image->sort_order,
        ];

        $image->fill($request->imageAttributes());
        $image->save();

        $image->load('project');

        AdminLogger::activity(
            'portfolio.gallery.update',
            sprintf('Updated a photograph in the %s gallery.', $image->project?->title ?? 'portfolio'),
        );
        AdminLogger::audit($image, 'updated', $before, [
            'project' => $image->project?->title,
            'caption' => $image->caption,
            'sort_order' => $image->sort_order,
        ]);

        return redirect()
            ->route('admin.portfolio.gallery', ['project' => $image->portfolio_project_id])
            ->with('status', 'Photograph updated.');
    }

    public function destroy(PortfolioImage $image)
    {
        $projectId = $image->portfolio_project_id;
        $title = $image->project?->title ?? 'portfolio';

        AdminLogger::audit($image, 'deleted', [
            'project' => $title,
            'path' => $image->path,
            'caption' => $image->caption,
        ], null);

        /*
         | The file goes before the row. Doing it the other way round would leave a
         | file on disk with nothing pointing at it if the delete failed.
         */
        Storage::disk('public')->delete($image->path);
        $image->delete();

        AdminLogger::activity(
            'portfolio.gallery.delete',
            sprintf('Deleted a photograph from the %s gallery.', $title),
        );

        return redirect()
            ->route('admin.portfolio.gallery', ['project' => $projectId])
            ->with('status', 'Photograph deleted.');
    }

    /**
     * An unknown view falls back to the grid rather than erroring, so a stale
     * bookmark still opens the screen.
     */
    private function resolveView(?string $view): string
    {
        return array_key_exists((string) $view, self::VIEWS) ? (string) $view : 'grid';
    }
}
