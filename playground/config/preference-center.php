<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The public pages. The prefix is deliberately not `/preferences`: a host
    | that already owns that URL should not have to fight this addon for it.
    |
    */

    'routes' => [
        'enabled' => env('PREFERENCE_CENTER_ROUTES', true),
        'prefix' => env('PREFERENCE_CENTER_ROUTE_PREFIX', '!/preference-center'),
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | Each block comes from a package that may or may not be installed. `auto`
    | asks the container and the class map; `true` and `false` override that.
    |
    | Turning a source off does not hide it from a determined poster: the write
    | paths refuse an absent source as firmly as the render path omits it.
    |
    */

    'sources' => [
        'marketing' => env('PREFERENCE_CENTER_SOURCE_MARKETING', 'auto'),
        'notifications' => env('PREFERENCE_CENTER_SOURCE_NOTIFICATIONS', 'auto'),
        'suppression' => env('PREFERENCE_CENTER_SOURCE_SUPPRESSION', 'auto'),
        'sequences' => env('PREFERENCE_CENTER_SOURCE_SEQUENCES', 'auto'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Magic link
    |--------------------------------------------------------------------------
    |
    | The third way in, for the person who has no account and no old mail.
    |
    | `ttl_minutes` is the life of the signed URL. It is short on purpose and it
    | is not the same trade-off as the marketing unsubscribe token, which never
    | expires because an unsubscribe link in a two-year-old mail must still
    | work. Nothing in a two-year-old mail points here.
    |
    | `min_response_ms` is a floor under the response time of a link request, so
    | the fast path (no such address, nothing to do) cannot be told from the
    | slow one (address found, mail queued). Raise it if your mailer is slower
    | than this on the machine that serves the request.
    |
    */

    'magic_link' => [
        'enabled' => env('PREFERENCE_CENTER_MAGIC_LINK', true),
        'ttl_minutes' => env('PREFERENCE_CENTER_MAGIC_LINK_TTL', 30),
        'session_minutes' => env('PREFERENCE_CENTER_MAGIC_LINK_SESSION', 60),
        'min_response_ms' => env('PREFERENCE_CENTER_MAGIC_LINK_FLOOR_MS', 350),

        // Off, and it should stay off. On, this endpoint mails a signed link to
        // anything typed into it, which is an open relay with extra steps. It
        // exists for the one honest case: an installation with neither a
        // marketing list nor a contact store, where nobody is known yet.
        'allow_unknown_addresses' => env('PREFERENCE_CENTER_MAGIC_LINK_ALLOW_UNKNOWN', false),

        'throttle' => [
            // Per address: what stops one mailbox being flooded.
            'per_address' => [
                'max' => env('PREFERENCE_CENTER_THROTTLE_ADDRESS_MAX', 3),
                'decay_minutes' => env('PREFERENCE_CENTER_THROTTLE_ADDRESS_DECAY', 60),
            ],
            // Per origin: what stops this endpoint being used as an amplifier
            // to mail a list of addresses somebody else owns.
            'per_origin' => [
                'max' => env('PREFERENCE_CENTER_THROTTLE_ORIGIN_MAX', 10),
                'decay_minutes' => env('PREFERENCE_CENTER_THROTTLE_ORIGIN_DECAY', 60),
            ],
        ],

        'from' => [
            'address' => env('PREFERENCE_CENTER_MAIL_FROM'),
            'name' => env('PREFERENCE_CENTER_MAIL_FROM_NAME'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery — what a mail service provider does to the link in transit
    |--------------------------------------------------------------------------
    |
    | A magic link leaves here as a signed, expiring URL and does not always
    | arrive as one. Providers that count clicks rewrite every `href` in the HTML
    | part onto their own redirector and append their own parameters when they
    | forward the reader. Laravel signs the whole query string, so one appended
    | parameter is a 403 — measured on staging, where Brevo's counter turned a
    | working link into `403 …/link/…?_se=…&expires=…&signature=…`.
    |
    | Two answers, and a host wants both.
    |
    |
    | `mail_headers` — stop the rewriting at the source.
    |
    | Most providers take a per-message header that turns click tracking off for
    | that one message, which is the right setting for a magic link: it is
    | transactional, nobody wants a click rate for it, and an untouched link
    | cannot be broken. Anything listed here is added verbatim to the outgoing
    | message, so this package needs no provider of its own and no dependency on
    | one. Empty by default — an addon that guessed your provider and changed how
    | it behaves would be worse than one that asks.
    |
    | Verified against each vendor's own documentation, July 2026:
    |
    |   Mailgun        'X-Mailgun-Track-Clicks' => 'no'
    |   Postmark       'X-PM-TrackLinks' => 'None'
    |   SparkPost      'X-MSYS-API' => '{"options":{"click_tracking":false}}'
    |   SendGrid       'X-SMTPAPI' => '{"filters":{"clicktrack":{"settings":{"enable":0}}}}'
    |   Mailjet        'X-Mailjet-TrackClick' => '0'
    |   Mandrill       'X-MC-Track' => 'opens'   (an allow-list: anything not
    |                                             named is switched off)
    |   Elastic Email  'trackclicks' => 'false'
    |
    |   Amazon SES     no header. SES rewrites links only when the configuration
    |                  set named in `X-SES-CONFIGURATION-SET` publishes click
    |                  events, so sending without that header is already the off
    |                  position. Per link: `<a ses:no-track href="…">`.
    |   Resend         no header; tracking is off by default, per domain.
    |   Brevo          no header, and none is coming. `X-Mailin-custom`,
    |                  `X-Sib-Sandbox` and `X-SIB-API` are the documented ones
    |                  and none of them touches tracking; the transactional API
    |                  has no tracking option in its body either. Brevo has
    |                  declined the request for years and says so in its own
    |                  community forum. On Brevo the ignore list below is not
    |                  defence in depth — it is the only thing that works.
    |
    |
    | `ignored_query_parameters` — survive the rewriting when it happens.
    |
    | These names are left out of the signature check. That is a real cost and
    | it is bounded: what a magic link carries is encrypted into the path, not
    | the query, and `expires` stays signed — `TrackingParameters` refuses to
    | ignore it however this list is edited. Every name below is a parameter a
    | mail provider adds to somebody else's URL. Names that only an ad network
    | or a referrer adds — `gclid`, `fbclid` — are deliberately absent: they do
    | not appear on the path from a mail to this route, and a list that grows by
    | association is how one ends up ignoring the wrong thing.
    |
    */

    'delivery' => [

        'mail_headers' => [
            // 'X-Mailgun-Track-Clicks' => 'no',
        ],

        'ignored_query_parameters' => [
            // Brevo (Sendinblue): the recipient address, base64, appended by the
            // click redirector. This is the one that was measured.
            '_se',

            // Brevo's "Google Analytics tagging", and the same switch in
            // Mailchimp, Mailjet, Postmark and Klaviyo: five parameters appended
            // to every link in the message.
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',

            // Mailchimp: campaign id and recipient id.
            'mc_cid',
            'mc_eid',

            // HubSpot: the encrypted recipient token and the message id.
            '_hsenc',
            '_hsmi',

            // Marketo (Adobe): the recipient token.
            'mkt_tok',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Audit
    |--------------------------------------------------------------------------
    |
    | Every applied change is announced as an event and written to a log with
    | the proof that authorised it. When LeadHub is installed the same record
    | also lands on the contact timeline, where a data-subject request will
    | look for it.
    |
    */

    'audit' => [
        'log_channel' => env('PREFERENCE_CENTER_AUDIT_LOG'),
        'leadhub' => env('PREFERENCE_CENTER_AUDIT_LEADHUB', true),
    ],

];
