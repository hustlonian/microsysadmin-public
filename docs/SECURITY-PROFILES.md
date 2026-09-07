# Installation security profiles

| Capability | Safe | Developer | Advanced |
|---|---:|---:|---:|
| Browser terminal | Disabled | Optional | Optional |
| Code Runner | Disabled | Optional | Optional |
| SQL import | Disabled | Enabled | Enabled |
| Privileged helper | Disabled | Enabled when configured | Enabled when configured |
| Service mutation | Restricted | Enabled by driver | Enabled by driver |

**Safe** is for demonstrations and less-trusted environments. Demo mode always resolves to Safe and routes UI/agent operations to fixtures.

**Developer** assumes local/private-network use. Dangerous tools still require permissions, CSRF validation, configured binaries, and explicit terminal confirmation.

**Advanced** exposes the same current capability set as Developer but records explicit risk acknowledgement and is reserved for broader future machine controls. It does not create an OS sandbox.

Administrators may tighten a profile at any time. Changing to Advanced requires an explicit acknowledgement. Entitlements and environment availability can further reduce a profile's capabilities; a profile cannot override a denied entitlement.
