# Test fixtures

These files are captures from live players, so they are not in the repository.
`tests/fixtures/*` is ignored, and four offline suites read from here:

| File | Read by | What it proves |
| --- | --- | --- |
| `streamfile.ts`, `vidara.ts` | `test_ts.php`, `test_es.php`, `test_mp4.php` | MPEG-TS demuxing, H.264 and AAC bitstream parsing, then the pure-PHP TS to MP4 remux |
| `luluvido.ts`, `luluvido.key` | same three | the same path through an AES-128 encrypted HLS segment |
| `luluvido.html` | `test_unpacker.php` | the packer and string-concatenation hiding a real player config uses |
| `*.m3u8` | `test_es.php` | playlist parsing with a key attribute |

Without them those four files exit with code 3 and print `SKIPPED`, because a
green run that checked nothing would be a false claim. The suites that do not
need captures (`test_deep.php`, `test_security.php`, `test_streamtape_eval.php`)
run everywhere.

To make them, fetch one segment of a short public clip through the app's own
network layer and save the bytes here:

```
php -r 'require "lib/support.php"; $h = new HttpClient(); $r = $h->get("<segment url>", ["headers" => ["Referer" => "<page url>"]]); file_put_contents("tests/fixtures/myclip.ts", $r->body);'
```

Save the decryption key for an encrypted stream as `<name>.key`, containing the
raw 16 bytes. Keep keys out of any commit: they are access to someone else's
file, not test data.
