# Pilot WMS for WordPress

A WordPress plugin that connects your site to [Pilot WMS](https://pilotwme.com). When you publish, update, or retract content in Pilot, a signed webhook delivers it to WordPress automatically — creating draft posts ready for your editorial review.

## How It Works

Pilot WMS generates content through AI-powered editorial workflows grounded in your knowledge base. The CMS Webhook channel pushes that content to external systems via signed HTTP POST requests. This plugin is the WordPress receiver.

```
Pilot WMS                          WordPress
┌──────────┐    signed POST       ┌──────────────────┐
│ Publish   │ ──────────────────► │ /pilot/v1/webhook │
│ to CMS    │  HMAC-SHA256        │                   │
│ channel   │  verified           │ Creates draft     │
│           │ ◄────────────────── │ post + image      │
│ Stores    │  { external_id,     │                   │
│ post ID   │    external_url }   │                   │
└──────────┘                      └──────────────────┘
```

Posts land as **drafts** (or pending review). Nothing goes live on your site until you hit Publish in WordPress. You retain full editorial control.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A Pilot WMS account with a CMS Webhook channel

## Installation

1. Download or clone this repository:

   ```bash
   git clone https://github.com/dhabib/pilot-wordpress-plugin.git
   ```

2. Copy the plugin folder into your WordPress installation:

   ```bash
   cp -r pilot-wordpress-plugin /path/to/wp-content/plugins/pilot-wms
   ```

3. In your WordPress admin, go to **Plugins** and activate **Pilot WMS**.

4. Go to **Settings > Pilot WMS** and paste your webhook secret (from the Pilot console).

## Configuration

### In Pilot WMS

Create a CMS channel in the Pilot console (**Channels > New Channel > CMS**):

| Field | Value |
|---|---|
| **Webhook URL** | `https://your-site.com/wp-json/pilot/v1/webhook` |
| **Content Format** | HTML |
| **Events** | `content.published`, `content.updated`, `content.unpublished` |
| **Authentication** | Optional — the HMAC signature is the primary auth mechanism |

Copy the auto-generated **Webhook Secret** (starts with `whsec_`).

Click **Test Webhook** to verify the connection. You should see a `200 OK` with `"Pilot WMS webhook is configured and working."`.

### In WordPress

Navigate to **Settings > Pilot WMS**:

| Setting | Default | Description |
|---|---|---|
| **Webhook Secret** | *(empty)* | The `whsec_` secret from your Pilot channel. Required. |
| **Default Category** | Uncategorized | Category assigned when the payload topic doesn't match an existing WordPress category. |
| **Post Status** | Draft | Status for newly created posts. Choose `Draft` or `Pending Review`. |
| **Marker Tag** | `pilot-wms` | Tag applied to every post created by the plugin, so you can filter them. |

The settings page shows a connection status indicator — green when the secret is configured, red when it's missing.

## What the Plugin Does

### On `content.published`

1. Verifies the HMAC signature and timestamp (rejects replays older than 5 minutes)
2. Checks for an existing post with the same Pilot projection ID (idempotency — prevents duplicates)
3. Creates a WordPress post:
   - **Title, body, excerpt, slug** from the payload
   - **Author**: a dedicated "Staff" user (`pilot-staff`, author role) created on activation
   - **Category**: matched by the payload's `topic_region` metadata against your WordPress category slugs, falling back to the configured default
   - **Tag**: the configured marker tag (default `pilot-wms`)
   - **Status**: `draft` or `pending` per your settings
4. If the payload includes an image URL, downloads it into the WordPress media library and sets it as the featured image
5. Stores Pilot metadata as hidden post meta (`_pilot_projection_id`, `_pilot_tenant_id`, `_pilot_delivery_id`, `_pilot_source_artifact_ids`)
6. Returns the WordPress post ID and permalink to Pilot, which stores them for future updates

### On `content.updated`

1. Finds the existing post by the WordPress post ID that Pilot stored from the original publish (falls back to projection ID meta query)
2. Updates the title, body, excerpt, slug, and category
3. Re-downloads the featured image only if the URL changed
4. Preserves the post's current status — if you've already published it, it stays published

### On `content.unpublished`

1. Finds the post by WordPress post ID
2. Sets its status to `draft`
3. If the post doesn't exist (already deleted), returns success (idempotent)

## Webhook Security

Every request from Pilot includes two headers for verification:

| Header | Value |
|---|---|
| `X-Pilot-Signature` | HMAC-SHA256 hex digest |
| `X-Pilot-Timestamp` | Unix timestamp (seconds) |

The plugin verifies the signature by computing:

```
expected = HMAC-SHA256(key: webhook_secret, message: timestamp + "." + raw_body)
```

and comparing it to the `X-Pilot-Signature` header using a timing-safe comparison (`hash_equals`). Requests with timestamps older than 5 minutes are rejected to prevent replay attacks.

Additional headers sent by Pilot (available for debugging):

| Header | Description |
|---|---|
| `X-Pilot-Event` | Event type (`content.published`, `content.updated`, `content.unpublished`, `test`) |
| `X-Pilot-Delivery` | Unique delivery ID for tracing |
| `X-Pilot-Retry-Count` | `0` on first attempt, increments on retries |
| `User-Agent` | `PilotWMS-Webhook/1.0` |

## Payload Reference

### `content.published` / `content.updated`

```json
{
  "event": "content.published",
  "timestamp": "2026-03-02T14:30:00Z",
  "delivery_id": "del_abc123",
  "tenant": {
    "id": "ee0676db-...",
    "slug": "builders-benchmark"
  },
  "content": {
    "projection_id": "proj_abc123",
    "external_id": "42",
    "title": "The Three-Legged Stool of Residential Insulation Is Breaking",
    "slug": "residential-insulation-stool-breaking",
    "body": "<p>Spray foam insulation is overspecified...</p>",
    "format": "article",
    "summary": "Research shows cellulose and mineral wool outperform spray foam.",
    "image_url": "https://production-pilot-artifacts.s3.amazonaws.com/.../image.png",
    "image_alt": "Residential wall cross-section showing insulation layers",
    "source_artifacts": [
      {
        "id": "art_xyz789",
        "file_name": "ORNL_research_2023.pdf",
        "headline": "Cost-Performance Analysis of Residential Wall Insulation Systems",
        "author": "Kosny, J. & Desjarlais, A."
      }
    ],
    "published_at": "2026-03-02T14:30:00Z",
    "metadata": {
      "topic_region": "energy-efficiency"
    }
  }
}
```

`external_id` is only present on `content.updated` events — it's the WordPress post ID returned by this plugin on the original publish.

### `content.unpublished`

```json
{
  "event": "content.unpublished",
  "timestamp": "2026-03-04T09:00:00Z",
  "delivery_id": "del_def456",
  "tenant": { "id": "ee0676db-...", "slug": "builders-benchmark" },
  "content": {
    "projection_id": "proj_abc123",
    "external_id": "42",
    "title": "The Three-Legged Stool of Residential Insulation Is Breaking",
    "slug": "residential-insulation-stool-breaking",
    "body": "",
    "format": "article",
    "summary": "",
    "source_artifacts": [],
    "published_at": "2026-03-04T09:00:00Z",
    "metadata": {}
  }
}
```

### `test`

```json
{
  "event": "test",
  "timestamp": "2026-03-02T14:00:00Z",
  "delivery_id": "test-1709392800000",
  "tenant": { "id": "ee0676db-...", "slug": "builders-benchmark" },
  "message": "This is a test webhook from Pilot WMS."
}
```

## Post Meta

The plugin stores Pilot metadata on each post as hidden custom fields:

| Meta Key | Description |
|---|---|
| `_pilot_projection_id` | Pilot's unique ID for this content projection |
| `_pilot_tenant_id` | Pilot tenant ID |
| `_pilot_delivery_id` | ID of the most recent webhook delivery |
| `_pilot_source_artifact_ids` | Serialized array of source artifact IDs (provenance) |
| `_pilot_image_url` | URL of the current featured image (used for change detection on updates) |

You can query these in your theme or other plugins:

```php
$projection_id = get_post_meta( $post_id, '_pilot_projection_id', true );
$source_ids    = get_post_meta( $post_id, '_pilot_source_artifact_ids', true ); // array
```

## Retry Behavior

If the plugin returns an error (5xx, timeout, etc.), Pilot retries with exponential backoff:

| Attempt | Delay |
|---|---|
| 1 | Immediate |
| 2 | 1 minute |
| 3 | 5 minutes |
| 4 | 30 minutes |
| 5 | 2 hours |
| 6 | 8 hours |
| 7 (final) | 24 hours |

After 7 failed attempts, the delivery is marked as failed in the Pilot console with a manual retry button. The plugin is fully idempotent — retries and duplicate deliveries update the existing post rather than creating duplicates.

## Troubleshooting

**"Webhook secret is not configured" (500)**
Go to Settings > Pilot WMS and paste the `whsec_` secret from your Pilot channel.

**"Webhook signature verification failed" (401)**
The secret in WordPress doesn't match the secret in Pilot. Copy-paste it again — make sure there are no trailing spaces.

**"Webhook timestamp is too old" (401)**
The request took more than 5 minutes to arrive, or your server's clock is significantly off. Check that your server time is synced (`timedatectl` on Linux, or check with your host).

**Test webhook works but posts aren't created**
Check that you've enabled the `content.published` event on your CMS channel in the Pilot console. The test event is always sent regardless of event configuration.

**Images aren't downloading**
The WordPress server needs outbound HTTPS access to `production-pilot-artifacts.s3.amazonaws.com`. Some hosting providers block outbound requests — check with your host. The post will still be created; only the featured image will be missing.

**Posts are appearing as a different author**
The plugin creates a `pilot-staff` user on activation. If that user was deleted, the plugin recreates it on the next webhook. Posts are always attributed to this user.

## File Structure

```
pilot-wordpress-plugin/
├── pilot-wms.php                      # Plugin bootstrap, activation hook, constants
├── includes/
│   ├── class-pilot-settings.php       # Settings page (Settings > Pilot WMS)
│   ├── class-pilot-webhook.php        # REST endpoint + HMAC signature verification
│   ├── class-pilot-post-handler.php   # Create / update / unpublish post logic
│   └── class-pilot-image-handler.php  # Download + sideload featured images
└── readme.txt                         # WordPress.org plugin readme
```

## License

GPL-2.0-or-later
