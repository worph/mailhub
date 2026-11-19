# mailhub

Bookmark for the repos behind the Yundera store's **MailHub** app: several mail accounts mirrored into one
mailbox, with webmail, calendar, contacts and an MCP server for AI assistants. Each repo is a submodule here;
the app itself (compose, seeded config, setup scripts, rationale) lives in the store:
`Yundera/AppStore` → `Apps/MailHub`.

| Submodule | Builds | What it is |
|---|---|---|
| [mailhub-roundcube](https://github.com/worph/mailhub-roundcube) | `ghcr.io/worph/mailhub-roundcube` | The webmail: Roundcube with the Kolab calendar and contacts, identity_smtp and the `mailhub_*` plugins (SSO, mail accounts, merged folders, archive, calendar & contacts with calendar subscriptions, AI assistant settings, branding, push notifications) |
| [mailhub-mirror](https://github.com/worph/mailhub-mirror) | `ghcr.io/worph/mailhub-mirror` | Every provider mailbox as a local copy, actions replayed at the provider, the merged views, Web Push, calendar subscriptions; also runs mailhub-submit (one SMTP login for mail apps) |
| [mailhub-mcp](https://github.com/worph/mailhub-mcp) | `ghcr.io/worph/mailhub-mcp` | MCP server over the mailbox, calendar and contacts |
| [mailhub_collect](https://github.com/worph/mailhub_collect) | — (pinned by commit in mailhub-roundcube's Dockerfile) | Roundcube plugin: *Collected recipients* filled from the mail already in the mailbox |

```sh
git clone --recurse-submodules https://github.com/worph/mailhub.git
```

## Releasing

Work, commit and tag inside the submodule. A tag `vX.Y.Z` on mailhub-roundcube, mailhub-mirror or mailhub-mcp
publishes that image (amd64 + arm64) as `X.Y.Z`; then bump its tag in the store's
`Apps/MailHub/docker-compose.yml` (and the app's `version`), and the submodule pointer here. mailhub_collect is
released by bumping `MAILHUB_COLLECT_COMMIT` in mailhub-roundcube's Dockerfile.
