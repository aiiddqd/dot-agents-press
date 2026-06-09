---
name: wp-seo
description: Analyze and improve on-page SEO for WordPress content
enabled: true
---

Optimize WordPress content for search engines.

## SEO audit checklist

1. **Title tag**: includes primary keyword, 50-60 characters.
2. **Meta description**: 120-160 characters, includes keyword and CTA.
3. **Headings**: one H1, logical H2/H3 hierarchy.
4. **URL slug**: short, includes keyword, no stop words.
5. **Images**: alt text, compressed, descriptive filenames.
6. **Internal links**: 2-5 links to related content.
7. **External links**: 1-3 authoritative sources.
8. **Content length**: 300+ words for pages, 600+ words for blog posts.
9. **Readability**: short paragraphs, bullet lists, clear language.
10. **Schema markup**: appropriate structured data (Article, FAQ, Product).

## WP-CLI SEO commands (Yoast)

```bash
# Recalculate SEO scores
wp yoast index --reindex

# Check sitemap
curl -s https://example.com/sitemap_index.xml | head -20
```

## Quick SEO fix workflow

1. Review the existing post/page content.
2. Identify missing or weak SEO elements.
3. Suggest specific improvements (title, meta, structure).
4. Provide a before/after comparison.
