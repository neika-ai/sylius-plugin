# Changelog

## v0.3.0

### Breaking

**The backend calls `/neika-chatbot/v1/*` and opens `/neika-chatbot/pair`.** Needs `neika-ai/sdk` and `neika-ai/symfony-bundle` `^0.3` and a Neika backend from 2026-11-11 or later. Update the firewall and access control:

```diff
-            pattern: ^/chatbot/v1
+            pattern: ^/neika-chatbot/v1
 ...
-        - { path: ^/chatbot/v1, roles: ROLE_CHATBOT_BACKEND }
+        - { path: ^/neika-chatbot/v1, roles: ROLE_NEIKA_CHATBOT_BACKEND }
```

- The widget renders as `<neika-chatbot-widget>` and loads `https://neika.b-cdn.net/widget/neika-chatbot.js` when `cdn_url` is empty.

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
