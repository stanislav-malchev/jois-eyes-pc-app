# Device & environment setup (Android phone)

## One-time phone setup
1. Install Tailscale from Play Store, log into the tailnet, enable
   "Always-on VPN" (Android VPN settings) so sync works unattended.
2. Sideload JoisEyes (ADB or APK install; enable install-unknown-sources for
   the file manager used).
3. Samsung Health → Settings → Health Connect: turn ON sharing for Heart rate,
   Steps, Sleep (each type individually; some are off by default).
4. In JoisEyes status screen, complete in order:
    - Grant location (fine → background "Allow all the time")
    - Grant Health Connect permissions (+ background read on Android 15)
    - Set endpoint URL and shared token
    - Tap "Ignore battery optimizations" and accept
5. Settings → Apps → JoisEyes → Battery → **Unrestricted**.
6. Settings → Battery → Background usage limits → add JoisEyes to
   **Never sleeping apps**.

## Known Samsung/HC facts
- Samsung Health does NOT export stress to Health Connect. Stress is out of
  scope on the phone; if desired later, derive it PC-side from heart-rate
  variability.
- Samsung Health writes to Health Connect in delayed batches; ingest lag of
  up to a few hours for sleep data is normal.

## PC side
- Run the sync service bound to the Tailscale interface only
  (e.g. listen on the machine's 100.x.y.z address), port 8787 suggested.
- Keep the shared token in a local config file; same value entered on phone.

## Smoke test checklist
- [ ] "Run now" location → row appears, pending count +1
- [ ] "Sync now" with PC up → pending drops to 0
- [ ] Kill PC service, "Sync now" → error logged, pending unchanged
- [ ] Restart PC service, "Sync now" → drains, no duplicates on PC
- [ ] Reboot phone → periodic work resumes without opening the app
- [ ] 24 h screen-off soak → no location gaps > 1 h, HC data present