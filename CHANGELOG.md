# Changelog

## v0.2.0

### Breaking

**Env vars are prefixed `NEIKA_`.** Rename them in `.env.local` and in every `%env()%` in your config:

```diff
-CHATBOT_BACKEND_URL=https://neika.ai
-CHATBOT_API_SECRET=…
-CHATBOT_INGEST_SECRET=…
-CHATBOT_SITE_KEY=…
+NEIKA_CHATBOT_BACKEND_URL=https://neika.ai
+NEIKA_CHATBOT_API_SECRET=…
+NEIKA_CHATBOT_INGEST_SECRET=…
+NEIKA_CHATBOT_SITE_KEY=…
```

The old names aren't read. Until you rename them, channels that aren't [paired](README.md#connect-from-the-dashboard)
have no widget and send no reports.

### Changed

- `get_order_status` marks the order number and the e-mail as [personal data](https://github.com/neika-ai/sdk#personal-data-in-tools).
- `get_product_availability` builds its cards with your `ProductViewFactoryInterface` service too, not only the product data source.

## v0.1.0

First release.
