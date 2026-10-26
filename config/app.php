<?php

return [
    // Site identity used by the UI and the <title>/meta tags.
    'brand' => [
        'name' => 'Grably',
        'tagline' => 'Paste a link. Get the video.',
        'sub_tagline' => 'Turn any supported video page into a direct MP4 download, with a preview before you save.',
        // Shown in search results. Keep it near 60 characters.
        'meta_title' => 'Grably: paste a video link, get an MP4',
    ],

    'app' => [
        // Set to true once the domain is pointed at this folder.
        'debug' => false,
        'timezone' => 'UTC',
        // Full origin of the live site, no trailing slash, eg https://grably.com
        // Empty falls back to the requested host. It feeds the canonical tag,
        // robots.txt and sitemap.xml, so set it before going live.
        'base_url' => '',
    ],

    'security' => [
        // Turn on only when the site really does sit behind a proxy or cdn that
        // rewrites the client address. While it is off, the rate limit keys on the
        // socket address, which a script cannot change per request.
        'trust_proxy_ip' => false,
    ],

    'http' => [
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        'connect_timeout' => 8,
        'timeout' => 20,
        'max_redirects' => 5,
        // HTML pages are read up to this many bytes; media is never fully read by the analyser.
        'page_max_bytes' => 2_000_000,
    ],

    'extract' => [
        // The deep reader walks documents, not one page: this is how many it may
        // open before it stops and reports what it already proved.
        'depth' => 3,               // iframe inside an iframe inside an iframe
        'max_pages' => 8,           // documents opened per analysis, page included
        'frames_per_page' => 5,     // embeds chased out of one document
        'json_configs' => 3,        // player config files fetched out of one document
        'deep_candidates' => 40,    // urls kept for ranking before verification
        'verify_limit' => 6,        // candidates actually probed, best first
        'request_limit' => 26,      // outbound requests allowed per analysis
        'deadline_ms' => 18000,     // stop reading and report what is proven
        'token_ttl_fallback' => 900, // assume a media token dies this fast when the host hides it
    ],

    'jobs' => [
        'ttl' => 1800,              // seconds an analysed link stays valid for relaying
        'max_age' => 86400,         // garbage-collect anything older
        'gc_probability' => 0.05,
    ],

    'limits' => [
        'max_concurrent_requests' => 3,
        'relay_buffer_bytes' => 262_144,
        // Hard ceiling for a single proxied download, 0 = unlimited.
        'relay_max_bytes' => 0,
    ],

    'hls' => [
        'default_height' => 1080,
        'segment_concurrency' => 4,
    ],

    // The MP4 builder runs one slice of a stream per http request, because a shared
    // host stops a script at 30 seconds.
    'build' => [
        'tick_seconds' => 10,
        'tick_segments' => 3,
        'max_segments' => 1500,
        'max_bytes' => 1_610_612_736,
        'segment_max_bytes' => 67_108_864,
        'segment_timeout' => 25,
        'playlist_max_bytes' => 4_194_304,
        'request_limit' => 900,
        'ttl' => 3600,
        'max_age' => 7200,
        // Shared plans bill by inode and fill up quietly, so the scratch folder
        // gets a ceiling of its own before another build is accepted.
        'scratch_budget' => 3_000_000_000,
    ],

    'logging' => [
        'enabled' => true,
        'dir' => 'storage/logs',
    ],

    'legal' => [
        'owner_notice' => 'Grably only fetches files a page already makes publicly available. Downloading a video you do not own or are not licensed to copy breaks the hosting site and copyright law.',
    ],
];
