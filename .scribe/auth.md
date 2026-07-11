# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_AUTH_KEY}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

Generate a token with <code>php artisan api:token owner@zenith.test</code> and paste it here. The <code>auth:sanctum</code> guard accepts this bearer token exactly like the SPA session cookie. Tenant endpoints (<code>/api/v1/*</code>) require a user that belongs to a company.
