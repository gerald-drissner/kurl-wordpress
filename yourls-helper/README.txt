kURL Helper 1.1.6

This helper is bundled inside the kURL WordPress plugin. No separate helper ZIP is required.

Recommended installation:
1. In WordPress, open kURL → Settings.
2. Click “Download bundled plugin.php” or copy the displayed code.
3. On the YOURLS server, create or open user/plugins/kurl-api/.
4. Put plugin.php in that folder, replacing an older copy.
5. Activate “kURL Helper” in the YOURLS plugin manager.
6. Return to WordPress and click “Test API”.

Security: every helper action requires valid YOURLS authentication (a signature
token, or username and password), also when the
YOURLS API is public (YOURLS_PRIVATE false, or YOURLS_PRIVATE_API false while
the admin area stays private). Version 1.1.5 and older relied on YOURLS's API
access control and must be replaced on installations with a public API.
