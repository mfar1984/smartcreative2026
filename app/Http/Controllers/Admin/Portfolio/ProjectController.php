<?php

namespace App\Http\Controllers\Admin\Portfolio;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PortfolioProjectRequest;
use App\Models\PortfolioProject;
use App\Services\AdminLogger;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProjectController extends Controller
{
    private const PER_PAGE = 15;

    /**
     * Where project images live on the public disk.
     */
    private const IMAGE_DIRECTORY = 'portfolio';

    public function index(Request $request)
    {
        $search = trim((string) $request->query('q'));
        $service = trim((string) $request->query('service'));
        $status = trim((string) $request->query('status'));

        $projects = PortfolioProject::query()
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($search) {
                $inner->where('title', 'like', "%{$search}%")
                    ->orWhere('client', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            }))
            ->when($service !== '', fn (Builder $query) => $query->where('service', $service))
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->inDisplayOrder()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.portfolio.index', [
            'projects' => $projects,
            'services' => PortfolioProject::SERVICES,
            'statuses' => PortfolioProject::STATUSES,
            'search' => $search,
            'service' => $service,
            'status' => $status,
            'isFiltered' => $search !== '' || $service !== '' || $status !== '',
            'publishedCount' => PortfolioProject::query()->published()->count(),
            'canCreate' => $request->user()->hasPermission('portfolio.create'),
            'canUpdate' => $request->user()->hasPermission('portfolio.update'),
            'canDelete' => $request->user()->hasPermission('portfolio.delete'),
        ]);
    }

    public function create()
    {
        return view('admin.portfolio.form', $this->formData(new PortfolioProject([
            'status' => PortfolioProject::STATUS_DRAFT,
            'service' => PortfolioProject::SERVICE_EVENTS,
            'delivered_on' => now(),
            'sort_order' => 0,
        ]), 'create'));
    }

    public function store(PortfolioProjectRequest $request)
    {
        $project = new PortfolioProject($request->projectAttributes());
        $project->slug = $this->resolveSlug($request->input('slug'), $request->input('title'));
        $project->image_path = $this->storeImage($request);
        $project->save();

        AdminLogger::activity('portfolio.create', sprintf('Added portfolio project %s.', $project->title));
        AdminLogger::audit($project, 'created', null, [
            'title' => $project->title,
            'slug' => $project->slug,
            'service' => $project->service,
            'status' => $project->status,
        ]);

        return redirect()
            ->route('admin.portfolio.index')
            ->with('status', sprintf('Project %s added.', $project->title));
    }

    public function edit(PortfolioProject $project)
    {
        return view('admin.portfolio.form', $this->formData($project, 'edit'));
    }

    public function update(PortfolioProjectRequest $request, PortfolioProject $project)
    {
        $before = [
            'title' => $project->title,
            'service' => $project->service,
            'status' => $project->status,
            'is_featured' => $project->is_featured,
            'sort_order' => $project->sort_order,
        ];

        $project->fill($request->projectAttributes());

        if ($request->filled('slug')) {
            $project->slug = $this->resolveSlug($request->input('slug'), $request->input('title'), $project->id);
        }

        $image = $this->storeImage($request, $project);

        if ($image !== null || $request->boolean('remove_image')) {
            $project->image_path = $image;
        }

        $project->save();

        AdminLogger::activity('portfolio.update', sprintf('Updated portfolio project %s.', $project->title));
        AdminLogger::audit($project, 'updated', $before, [
            'title' => $project->title,
            'service' => $project->service,
            'status' => $project->status,
            'is_featured' => $project->is_featured,
            'sort_order' => $project->sort_order,
        ]);

        return redirect()
            ->route('admin.portfolio.index')
            ->with('status', sprintf('Project %s saved.', $project->title));
    }

    public function destroy(PortfolioProject $project)
    {
        AdminLogger::audit($project, 'deleted', [
            'title' => $project->title,
            'slug' => $project->slug,
        ], null);

        $title = $project->title;

        if ($project->image_path) {
            Storage::disk('public')->delete($project->image_path);
        }

        $project->delete();

        AdminLogger::activity('portfolio.delete', sprintf('Deleted portfolio project %s.', $title));

        return redirect()
            ->route('admin.portfolio.index')
            ->with('status', sprintf('Project %s deleted.', $title));
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function formData(PortfolioProject $project, string $mode): array
    {
        return [
            'project' => $project,
            'mode' => $mode,
            'services' => PortfolioProject::SERVICES,
            'statuses' => PortfolioProject::STATUSES,
            // Reuse the categories already in use so the wording stays consistent
            // between entries without forcing a fixed list.
            'categories' => PortfolioProject::query()
                ->distinct()
                ->orderBy('category')
                ->pluck('category')
                ->all(),
        ];
    }

    /**
     * Use the given slug when supplied, otherwise derive one from the title,
     * adding a numeric suffix until it is free.
     */
    private function resolveSlug(?string $slug, string $title, ?int $ignoreId = null): string
    {
        $base = filled($slug) ? str($slug)->slug()->toString() : str($title)->slug()->toString();
        $base = $base !== '' ? $base : 'project';

        $candidate = $base;
        $suffix = 1;

        while (PortfolioProject::query()
            ->where('slug', $candidate)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $candidate = $base . '-' . (++$suffix);
        }

        return $candidate;
    }

    /**
     * Save an uploaded image and return its path, or null when nothing was
     * uploaded. Replacing an image removes the old file so the disk does not fill
     * with orphans.
     */
    private function storeImage(PortfolioProjectRequest $request, ?PortfolioProject $project = null): ?string
    {
        if ($request->boolean('remove_image') && $project?->image_path) {
            Storage::disk('public')->delete($project->image_path);

            return null;
        }

        if (! $request->hasFile('image')) {
            return $project?->image_path;
        }

        if ($project?->image_path) {
            Storage::disk('public')->delete($project->image_path);
        }

        return $request->file('image')->store(self::IMAGE_DIRECTORY, 'public');
    }
}
