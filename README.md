# Neika Sylius Plugin

Sylius plugin for the [Neika](https://neika.ai) chatbot: shop tools, catalog sources and the shop widget. Sylius 1.14 and 2.x.

Built on [`neika-ai/symfony-bundle`](https://github.com/neika-ai/symfony-bundle). Its README covers the endpoints,
error format, locales, and adding your own tools and sources.

## Features

- [Shipped tools](#shipped-tools) — the chat answers order status and stock from live shop data
- [Shipped sources](#shipped-sources) — products, categories and CMS pages, linked on each channel's own domain
- [Catalog change notifications](#catalog-change-notifications) — a saved product reaches the chat without waiting for the nightly sync
- [Widget](#widget) — the chat on every shop page, no template edits
- [Connect from the dashboard](#connect-from-the-dashboard) — one click pairs a channel; nobody copies a secret
- [Credentials](#credentials) — one site for the whole shop, or one per channel
- [Chat click tracking](#chat-click-tracking) — revenue from orders that started in the chat
- [Orders from chat](#orders-from-chat) — open the conversation behind an order from its admin page

## Install

```bash
composer require neika-ai/sylius-plugin
```

## Usage

1. `config/bundles.php` (Symfony Flex already did this if it's on):

   ```diff
    return [
        // ...
   +    Neika\SymfonyBundle\NeikaSymfonyBundle::class => ['all' => true],
   +    Neika\SyliusPlugin\NeikaSyliusPlugin::class => ['all' => true],
    ];
   ```

2. `config/routes/neika.yaml`:

   ```yaml
   neika:
       resource: '@NeikaSymfonyBundle/config/routes.php'
   ```

   **Keep these routes off the shop's `_locale` prefix.** The backend calls `/neika-chatbot/v1/*` on the shop host, and
   the dashboard's Connect button opens `/neika-chatbot/pair`.

3. `config/packages/security.yaml`. **Put the firewall before the Sylius `shop` firewall**, because `shop` matches `^/`:

   ```diff
    security:
   +    providers:
   +        chatbot_backend:
   +            memory:
   +                users: []
        firewalls:
   +        chatbot_api:
   +            pattern: ^/neika-chatbot/v1
   +            stateless: true
   +            provider: chatbot_backend
   +            entry_point: Neika\SymfonyBundle\Security\ApiAuthenticationFailureHandler
   +            access_token:
   +                token_handler: Neika\SymfonyBundle\Security\ApiSecretAuthenticator
   +                failure_handler: Neika\SymfonyBundle\Security\ApiAuthenticationFailureHandler
            shop:
                # ...
        access_control:
   +        - { path: ^/neika-chatbot/v1, roles: ROLE_NEIKA_CHATBOT_BACKEND }
   ```

4. `config/packages/neika.yaml`:

   ```yaml
   neika:
       backend_url: '%env(NEIKA_CHATBOT_BACKEND_URL)%'
   ```

5. `.env.local`. Skip the three credentials if you [connect from the dashboard](#connect-from-the-dashboard):

   ```diff
   +NEIKA_CHATBOT_BACKEND_URL=https://neika.ai
   +NEIKA_CHATBOT_API_SECRET=change-me
   +NEIKA_CHATBOT_INGEST_SECRET=change-me
   +NEIKA_CHATBOT_SITE_KEY=site-key
   ```

## Configuration

The `neika:` options belong to the Symfony bundle; see its [Configuration](https://github.com/neika-ai/symfony-bundle#configuration).
Two of them work differently here:

| Option | In this plugin |
|---|---|
| `widget.enabled` | `false` means the plugin injects no widget. |
| `widget.defer` | `false` means a plain `<script src>` without `defer`. |

> **Set both as literal booleans.** The plugin reads them before env vars resolve, so an `%env()%` value counts as `true`.

## Credentials

**The widget needs only a site key.** Catalog notifications and order reports also need an ingest secret. Each
channel takes the first row that matches:

| Channel | Site key | Ingest secret |
|---|---|---|
| [paired](#connect-from-the-dashboard) | stored on the channel | stored on the channel |
| listed in `channel_site_keys` | the listed key | `NEIKA_CHATBOT_INGEST_SECRET` |
| listed in `channel_site_keys` with `''` | none, so no widget and no reports | none |
| any other | `NEIKA_CHATBOT_SITE_KEY` | `NEIKA_CHATBOT_INGEST_SECRET` |

A site key without an ingest secret still shows the widget. Notifications and order reports are skipped.

The backend's calls to `/neika-chatbot/v1` are accepted with `NEIKA_CHATBOT_API_SECRET` or the API secret of any paired channel.

> **A paired API secret works on every channel**, not only on the channels it was paired with.

### One site per channel

**Map channel codes to site keys when each channel is its own site on the backend.**

`config/packages/neika_sylius.yaml`

```yaml
neika_sylius:
    channel_site_keys:
        CZ_WEB: '%env(NEIKA_CHATBOT_SITE_KEY_CZ)%'
        SK_WEB: '%env(NEIKA_CHATBOT_SITE_KEY_SK)%'
        DE_WEB: 'pk_live_de'
```

| Option | Default | Meaning |
|---|---|---|
| `channel_site_keys` | `{}` | `{ <channel code>: <site key> }`. Codes are taken as written (`cz-web` stays `cz-web`). Values are literal or `%env()%`. |

- **A listed channel never falls back to `NEIKA_CHATBOT_SITE_KEY`.** `''` turns the chatbot off for that channel.
- Every channel listed → leave `NEIKA_CHATBOT_SITE_KEY` empty.

### Your own store

Alias `ChannelCredentialsProviderInterface` to your service:

`config/services.yaml`

```yaml
services:
    Neika\SyliusPlugin\Credentials\ChannelCredentialsProviderInterface: '@App\Chatbot\VaultCredentialsProvider'
```

The Symfony bundle's `CredentialsProviderInterface` then points at the same service. For Connect to save into your
store too, also implement `Neika\SymfonyBundle\Contract\CredentialsWriterInterface` and alias it the same way.

> **Alias the Sylius interface.** Aliasing only `CredentialsProviderInterface` fails the container build, because
> Sylius looks credentials up per channel.

## Connect from the dashboard

**Click Connect on the tool-server connection in the chatbot dashboard.** The shop makes its secrets, sends them to
`backend_url` and stores them on the connection's channels. A connection that lists no channels pairs the channel of
the shop host. The new key works at once, no cache clear.

1. `src/Entity/Channel/Channel.php`:

   ```diff
   +use Neika\SyliusPlugin\Credentials\NeikaChannelInterface;
   +use Neika\SyliusPlugin\Credentials\NeikaChannelTrait;
    use Sylius\Component\Core\Model\Channel as BaseChannel;

   -class Channel extends BaseChannel
   +class Channel extends BaseChannel implements NeikaChannelInterface
    {
   +    use NeikaChannelTrait;
   ```

   > **Use `NeikaChannelInterface` only together with `NeikaChannelTrait`.** The lookup queries the trait's columns.

2. Add the `neika_site_key`, `neika_api_secret_hash` and `neika_ingest_secret` columns:

   ```bash
   bin/console doctrine:migrations:diff
   bin/console doctrine:migrations:migrate
   ```

3. Admin → Channels: set **Hostname** to the host of the connection's base URL (`shop.cz` for `https://shop.cz`).
   Case doesn't matter, `www.` does: `www.shop.cz` ≠ `shop.cz`.

   **Pairing several channels?** Each channel's hostname must be on a verified domain of the website
   (`shop.sk` or `eshop.shop.sk` for `shop.sk`). Otherwise nothing is saved.

4. Click **Connect** in the dashboard. You land back there when it's done.

Channels stay on their configured credentials until they're paired.

> **Unset `NEIKA_CHATBOT_API_SECRET` once every channel is paired.** It stays valid otherwise.

> The API secret is stored only as a SHA-256 hash. The ingest secret is stored as is, since it has to be sent.
> The trait maps the columns with Doctrine attributes and annotations. If your channel is mapped in XML, map
> `neikaSiteKey` (`string`, 64), `neikaApiSecretHash` (`string`, 64, fixed) and `neikaIngestSecret` (`string`, 128),
> all nullable, yourself.

What can go wrong in this plugin (the rest is in the bundle's [error table](https://github.com/neika-ai/symfony-bundle#connect-from-the-dashboard)):

| Situation | Result |
|---|---|
| Channel entity without the trait | 409 page: paste the credentials into the shop's configuration |
| The shop host's channel has no hostname | 400 `pairing_hostname_missing`, nothing sent |
| A paired channel has no hostname | 400 `pairing_hostname_missing`, nothing saved |
| A paired channel's hostname isn't on a verified domain | 400 `pairing_wrong_shop`, nothing saved |
| The connection names a channel code the shop doesn't have | 400 `pairing_unsupported`, nothing saved |

Click Connect again to retry.

## Shipped tools

The backend calls these mid-conversation:

- `get_order_status`: order state, payment and shipping state, tracking codes, item count and total. Needs the
  order number and the customer's e-mail. A wrong e-mail, an unknown number and an unplaced cart all get the same
  "not found". A pasted number with spaces or dashes is found too.
  The order number, e-mail and tracking codes are [personal data](https://github.com/neika-ai/sdk#personal-data-in-tools).
- `get_product_availability`: price, currency and stock for up to 20 variant codes, plus a `products` block for
  the widget.

**Their texts ship in cs, en, de, sk, pl, hu, ro, sl, hr and ru**, in the `neika` domain. A missing language falls
back to the channel's default locale. To override one or add a language, see
[Translations](https://github.com/neika-ai/symfony-bundle#translations).

## Shipped sources

The backend pulls these in bulk into its search index:

- `products`: one document per variant, for indexable products of the channel, per locale.
  - Metadata: `code` (variant), `productCode`, `name`, `url`, `imageUrl`, `priceMinor`, `currency`, `inStock`,
    `taxons` (taxon codes), `taxonNames` (in the requested locale), `attributes`, `options`, `associations`.
  - `associations`: association type name → `[{code, name}]`, one sellable variant per associated product. Left
    out when there are none.
  - Text: name, short description, description, attributes, options, taxon names and the main taxon path.
- `categories`: enabled taxons of the channel's tree, per locale (`code, name, path, url, productCount`).
  - The tree is the channel's **menu taxon** and everything under it. The menu taxon is served too.
  - Without a menu taxon, the tree is the shop's only taxon tree, root excluded. A shop with several trees
    answers `409 ambiguous_taxon_tree`; set a menu taxon on each channel.
  - A disabled taxon hides its whole branch. Disabling the menu taxon itself hides only the menu taxon.
  - `productCount` counts the taxon's whole subtree, only products that pass `ProductIndexabilityInterface`.
- `cms_pages`: enabled CMS pages of the channel, per locale. Registered only when
  [Monsieur Biz CMS](https://github.com/monsieurbiz/SyliusCmsPagePlugin) (pages inside their publish dates) or
  [BitBag CMS](https://github.com/BitBagCommerce/SyliusCmsPlugin) is installed.

**Every link a source or tool sends is on the channel's own hostname**, whatever host the request came in on.

- A channel without a hostname gets the request host.
- Sylius stores no scheme or port per channel, so both come from the request. For `neika:notify-all`, which runs
  outside a request, set `router.request_context.scheme` and `host`, or links come out as `http://`.

> `imageUrl` uses the `sylius_shop_product_original` filter. **Don't put a `liip_imagine` `cache` resolver in front
> of `web_path`.** It caches the absolute URL without the host, so every channel gets the first channel's host.

### Extension points

Replace one by redefining its container alias in `config/services.yaml`:

| Interface | Default | Decides |
|---|---|---|
| `ProductIndexabilityInterface` | `ProductIndexability` | Which variants `products` serves: enabled, product enabled and in the channel, priced in the channel. |
| `ProductViewFactoryInterface` | `ProductViewFactory` | Price, stock, image and URL in `products`. |
| `ChannelTaxonRootsInterface` | `ChannelTaxonRoots` | Which trees `categories` serves for a channel without a menu taxon. The default refuses to pick one of several trees; return every root if your channels share one taxonomy. |

## Catalog change notifications

**Saved catalog entries reach the backend after the response is sent**, so the next chat sees them. Nothing to set up.

| Added, saved or deleted | Announces |
|---|---|
| `Product`, `ProductVariant`, `ChannelPricing` | the product's variants, in every shop locale |
| `ProductTranslation` | the product's variants, in its own locale |
| `Taxon` | the category, in every shop locale; not its products |
| `TaxonTranslation` | the category, in its own locale |

> Editing only a product's associated products waits for the next bulk pull.

**Each change goes to every enabled channel that serves the locale.** Channels with the same site key share one request.
Requests go one at a time, at most 500 ids each.

> **The backend accepts notifications only from an active site.** A draft site answers 401, so activate it in the
> dashboard first. Order reports and the widget need an active site too.

| Situation | Result |
|---|---|
| Channel without a site key or ingest secret | skipped, warning logged |
| Channel listed with `''` | skipped, debug record |
| No enabled channel serves the locale | sent nowhere |
| `backend_url` empty | nothing sent |
| Backend asks to retry within 2 s | sent once more |
| Backend fails, or asks to wait longer | warning logged; the next nightly sync catches up |
| Over 20,000 changed ids in one request or command | the rest is dropped, warning logged |

The shop request never fails because of a notification.

### Re-announce everything

```bash
bin/console neika:notify-all [--source=products|categories|cms_pages] [--locale=cs_CZ] [--channel=code]
```

Sends one channel's whole catalog to its site in batches of 500 ids, 2 s apart. A `429` or `503` waits `Retry-After`
and sends the same batch again, up to 5 times.

- **Pass `--channel` when the CLI context resolves no channel.** Run it once per channel.
- A disabled channel, or one without a site key or ingest secret, stops the run.
- Without `--locale`, every locale of the channel is sent.
- `--locale` takes any locale the channel serves, in any spelling (`cs-cz` works). Anything else stops the run.

## Widget

**Every shop page gets the widget**, through the `sylius_shop.base#javascripts` Twig hook on Sylius 2 and the
`sylius.shop.layout.javascripts` template block on Sylius 1.14:

```html
<script src="{widget.cdn_url}" defer></script>
<neika-chatbot-widget site-key="{site key}" locale="{app.locale}" backend-url="{backend_url}"></neika-chatbot-widget>
```

- The site key is the current channel's, looked up on every render (`neika_site_key()`).
- **No site key → nothing renders**, neither the script nor the element.

> On Sylius 1.14 the container build reports `sylius/ui-bundle` deprecations for the template block. That's
> expected: 1.14 has no Twig Hooks.

To place it yourself, include the template with a plain context:

```twig
{% include '@NeikaSyliusPlugin/shop/widget.html.twig' with {
    backend_url: 'https://chatbot.example.com',
    widget_cdn_url: '',
    defer: true,
} only %}
```

## Chat click tracking

**Visits and orders from chat links are reported for you.** Nothing to set up beyond `backend_url` and credentials.

- **Visit:** the Symfony bundle [reports it](https://github.com/neika-ai/symfony-bundle#chat-click-tracking) with the
  current channel's site key.
- **Product page opened from a chat link** (`?neikaclid=…`): the click is remembered in the session. The last click
  per product wins, up to 20 products.
- **Order placed with a remembered product:** reported after the response is sent, with the order channel's site
  key. Revenue is the matching items' total, with tax and item discounts, without shipping, in the order's currency.
  Unpaid orders count, since the report goes out when the order is placed.
- **Any placed order clears the session**, with or without a chat product.

> Only shop checkout is covered (`sylius.order.post_complete`). Orders completed through the API or your own code
> aren't reported.

Backend unreachable → warning logged; checkout never fails. A non-https `backend_url` is skipped outside `dev`.

## Orders from chat

**Open the chat conversation behind an order from its admin page.** Add the interface and trait to your `Order` entity:

`src/Entity/Order/Order.php`

```diff
+use Neika\SyliusPlugin\Attribution\ChatAttributedOrderInterface;
+use Neika\SyliusPlugin\Attribution\ChatAttributedOrderTrait;
 use Sylius\Component\Core\Model\Order as BaseOrder;

-class Order extends BaseOrder
+class Order extends BaseOrder implements ChatAttributedOrderInterface
 {
+    use ChatAttributedOrderTrait;
```

Then add the `chat_click_ids` column:

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

The order keeps the click id of every chat link that led to one of its products, oldest first. The order detail
shows a **From chatbot** box at the bottom of the right column:

- Chat order → a numbered list with one **Open conversation** link per click, to the conversation in the Neika console.
- Any other order → `no`.

> The trait maps the column with Doctrine attributes and annotations. If your order is mapped in XML, map
> `chatClickIds` (`json`, nullable) yourself.

> Overriding `@SyliusAdmin/Order/show.html.twig` on Sylius 1 drops the sidebar blocks. Include the box yourself:
> `{% include '@NeikaSyliusPlugin/admin/order/from_chat.html.twig' %}`.

## License

MIT
