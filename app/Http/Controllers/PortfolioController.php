<?php

namespace App\Http\Controllers;

use App\Models\PortfolioProject;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The public Portfolio page.
 *
 * Reads published projects only. Drafts are invisible here, which is the whole
 * point of the status: work in progress can be written up over several sittings
 * without appearing half finished on the live site.
 */
class PortfolioController extends Controller
{
    private const PER_PAGE = 12;

    public function index(Request $request)
    {
        $service = (string) $request->query('service', '');

        // An unknown service in the query string is ignored rather than producing
        // an empty grid, which would look like a fault.
        if (! array_key_exists($service, PortfolioProject::SERVICES)) {
            $service = '';
        }

        $projects = PortfolioProject::query()
            // The lightbox reads these, so they are loaded up front rather than one
            // query per card.
            ->with('images')
            ->published()
            ->when($service !== '', fn (Builder $query) => $query->where('service', $service))
            ->inDisplayOrder()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.portfolio', [
            'pageTitle' => 'Portfolio',
            'pageSubtitle' => 'Events we have run and work we have delivered.',
            'projects' => $projects,
            'activeService' => $service,

            /*
             | Counts per service, so a filter that would lead to an empty grid is
             | never offered. One grouped query rather than one per tab.
             */
            'serviceCounts' => PortfolioProject::query()
                ->published()
                ->selectRaw('service, COUNT(*) as total')
                ->groupBy('service')
                ->pluck('total', 'service')
                ->all(),

            'totalPublished' => PortfolioProject::query()->published()->count(),
            'services' => PortfolioProject::SERVICES,
        ]);
    }
}
