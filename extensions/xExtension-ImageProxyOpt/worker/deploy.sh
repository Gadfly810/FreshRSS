#!/bin/sh
# Deploy the image proxy worker to your custom domain.
#
#   ./deploy.sh
#
# The domain lives in domain.local (gitignored, one line, no scheme) so that the repository
# itself never names your private endpoint. `wrangler deploy` reconciles triggers, so passing
# it every time is what keeps the custom domain bound -- a bare `wrangler deploy` would remove it.
set -e
cd "$(dirname "$0")"

if [ ! -s domain.local ]; then
	echo "missing domain.local -- write your worker hostname into it, e.g." >&2
	echo "  echo img.example.com > domain.local" >&2
	exit 1
fi

exec pnpx wrangler deploy --domain "$(tr -d '[:space:]' < domain.local)"
