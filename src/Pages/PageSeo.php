<?php

declare(strict_types=1);

namespace Starlite\Pages;

use Spatie\SchemaOrg\Schema;
use Starlite\Seo\Seo;

/**
 * Maps a page's front matter onto the page's SEO metadata: title, summary as meta description,
 * share image, plus a WebPage JSON-LD block.
 *
 * @phpstan-import-type Page from Pages
 */
final class PageSeo
{
    /** @param Page $page */
    public static function apply(Seo $seo, array $page): void
    {
        $seo->title($page['title'])->description($page['summary']);
        if ($page['image'] !== null) {
            $seo->image($page['image']);
        }

        $webPage = Schema::webPage()
            ->name($page['title'])
            ->description($page['summary'])
            ->url($seo->canonicalUrl())
            ->inLanguage($seo->site->language())
            ->isPartOf(Schema::webSite()->name($seo->site->name)->url($seo->url('/')));
        if ($page['updated'] !== null) {
            $webPage->dateModified(new \DateTimeImmutable($page['updated'], new \DateTimeZone('UTC')));
        }
        if ($seo->imageUrl() !== null) {
            $webPage->image($seo->imageUrl());
        }
        $seo->schema($webPage);
    }
}
