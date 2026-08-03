<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $perPage = (int) $request->integer('perPage', 10);

        // Keep the selector honest — only allow the sizes the UI offers.
        if (! in_array($perPage, [10, 20, 50, 100], true)) {
            $perPage = 10;
        }

        return Inertia::render('Dashboard', [
            'stats' => [
                'users' => 1_248,
                'active' => 312,
                'reports' => 87,
                'revenue' => 'Rp 42.6M',
            ],
            'records' => $this->records($request, $perPage),
        ]);
    }

    /**
     * A page of demo rows wrapped in a real Laravel paginator, so the
     * <Pagination> component receives the exact shape it will get from an
     * Eloquent `->paginate()` once real data is wired in.
     */
    private function records(Request $request, int $perPage): LengthAwarePaginator
    {
        $all = $this->sampleRecords();
        $page = (int) $request->integer('page', 1);

        $paginator = new LengthAwarePaginator(
            $all->forPage($page, $perPage)->values(),
            $all->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                // Preserve perPage (and any future filters) across page links.
                'query' => $request->query(),
            ],
        );

        return $paginator;
    }

    private function sampleRecords(): Collection
    {
        $firstNames = ['Andi', 'Budi', 'Citra', 'Dewi', 'Eka', 'Fajar', 'Gita', 'Hadi', 'Indah', 'Joko', 'Kirana', 'Lestari'];
        $lastNames = ['Santoso', 'Wijaya', 'Pratama', 'Putri', 'Nugroho', 'Halim', 'Saputra', 'Utami', 'Kusuma', 'Rahmawati'];
        $roles = ['Admin', 'Manager', 'Employee'];
        $statuses = ['active', 'pending', 'inactive'];

        return collect(range(1, 47))->map(function (int $i) use ($firstNames, $lastNames, $roles, $statuses) {
            $name = $firstNames[$i % count($firstNames)] . ' ' . $lastNames[$i % count($lastNames)];

            return [
                'id' => $i,
                'name' => $name,
                'email' => strtolower(str_replace(' ', '.', $name)) . '@kpn.co.id',
                'role' => $roles[$i % count($roles)],
                'status' => $statuses[$i % count($statuses)],
                'created_at' => now()->subDays($i)->format('d M Y'),
            ];
        });
    }
}
