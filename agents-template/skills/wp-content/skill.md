---
name: wp-content
description: Create, edit, and manage WordPress posts, pages, and media
enabled: true
---

Manage WordPress content through the REST API or WP-CLI.

## Create a post

```bash
wp post create --post_title="Title" --post_content="Content" --post_status="draft"
```

## List recent posts

```bash
wp post list --post_type=post --posts_per_page=10
```

## Update a post

```bash
wp post update <id> --post_title="New Title" --post_status="publish"
```

## Upload media

```bash
wp media import /path/to/image.jpg
```

## Content quality checklist

- Title: 40-60 characters, includes primary keyword.
- Excerpt: 120-160 characters, compelling summary.
- Content: well-structured with headings (H2, H3), short paragraphs.
- Images: at least one featured image, alt text on all images.
- Links: internal links to 2-3 related posts, 1-2 external links.
- Categories and tags assigned.

## REST API examples

```
GET  /wp-json/wp/v2/posts
POST /wp-json/wp/v2/posts
GET  /wp-json/wp/v2/posts/<id>
PUT  /wp-json/wp/v2/posts/<id>
```
