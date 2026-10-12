<?php

declare(strict_types=1);

namespace App\Helpers;

/** Page-number pagination for list screens (25 rows per page). */
final class Paginator
{
    public int $page;
    public int $perPage;
    public int $total = 0;

    public function __construct(int $perPage = 25)
    {
        $this->perPage = $perPage;
        $p = $_GET['page'] ?? '1';
        $this->page = (is_string($p) && ctype_digit($p) && (int) $p > 0) ? (int) $p : 1;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    /** Rendered prev/next links preserving the current query string (search, filters). */
    public function links(): string
    {
        if ($this->pages() <= 1) {
            return '<p class="help-text">' . $this->total . ' record' . ($this->total === 1 ? '' : 's') . '</p>';
        }
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $mk = function (int $page) use ($path): string {
            $q = $_GET;
            $q['page'] = $page;
            return e($path . '?' . http_build_query($q));
        };
        $html = '<nav class="pagination"><span class="help-text">Page ' . $this->page . ' of ' . $this->pages() . ' · ' . $this->total . ' records</span>';
        if ($this->page > 1) {
            $html .= ' <a class="btn btn-outline btn-sm" href="' . $mk($this->page - 1) . '">‹ Previous</a>';
        }
        if ($this->page < $this->pages()) {
            $html .= ' <a class="btn btn-outline btn-sm" href="' . $mk($this->page + 1) . '">Next ›</a>';
        }
        return $html . '</nav>';
    }
}
