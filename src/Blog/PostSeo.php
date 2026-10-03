<?php

declare(strict_types=1);

namespace Starlite\Blog;

use Spatie\SchemaOrg\Schema;
use Starlite\Seo\Seo;

/**
 * Maps a post's front matter onto the page's SEO metadata:
 * title, summary, share image, article dates and tags, plus a BlogPosting JSON-LD block.
 *
 * @phpstan-import-type Post from MarkdownParser
 */
final class PostSeo
{
    /** @param Post $post */
    public static function apply(Seo $seo, array $post): void
    {
        $seo->title($post['title'])
            ->description($post['summary'])
            ->article($post['date'], $post['updated'], $post['tags'])
            ->noindex($post['draft']);
        if ($post['image'] !== null) {
            $seo->image($post['image']);
        }

        $url = $seo->canonicalUrl();
        $author = $seo->site->author ?? $seo->site->name;
        $posting = Schema::blogPosting()
            ->headline($post['title'])
            ->description($post['summary'])
            ->datePublished($post['date'])
            ->dateModified($post['updated'] ?? $post['date'])
            ->url($url)
            ->mainEntityOfPage($url)
            ->author(Schema::person()->name($author))
            ->publisher(Schema::organization()->name($seo->site->name)->url($seo->url('/')));
        if ($post['tags'] !== []) {
            $posting->keywords(implode(', ', $post['tags']));
        }
        if ($seo->imageUrl() !== null) {
            $posting->image($seo->imageUrl());
        }
        $seo->schema($posting);
    }
}
