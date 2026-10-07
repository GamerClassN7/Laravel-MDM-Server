# Security scanner

## Security scanner

**Security** in the main menu is a small vulnerability scanner and SIEM: agents 1.17.0+ collect a
security inventory and the raw records of their security logs every hour (and with **Sync**) in a
background job at idle priority. The agent does not interpret the logs: **parser rules** on the
server turn their records into events, **detection rules** look at the inventory and the events and
open a **finding** for every item they match.

**The logs are optional and off by default** (they contain user names, addresses and commands):
the agent sends them only when `security_logs` is `on` in its `config.json` (`-SecurityLogs on` at
install). The portal cannot switch it on; the **Allow security logs** script (like **Allow network
scans**: a run only detects, **Remediate** is yours to start on the devices you pick) does it for
you. System admins can turn the logs off for all agents. The **Agent** tab of a device lists the
features of its agent (remediation scripts, network discovery, security inventory and logs, disk
health, Wake-on-LAN, pings) as On / Off, read only. The inventory (software, ports, settings) is
sent either way.

The agent sends the inventory as **deltas**: for each source (software, processes, ports, startup,
administrators, settings) it keeps the state (a hash of the hashes of its items) the server last
acknowledged and sends only the items that changed, or just the state when nothing did (a
collection with one new package is under 1 KB instead of 20 KB or more). The server applies all
deltas of a collection in one transaction and checks that the result has the state the agent
computed; a delta that does not follow what the server has is refused (`409`, nothing stored) and
the agent sends that source whole. A collection sent twice (`collection_id`) is processed once.

Logs are not sent whole either. The agent keeps a **position per log** (the journal cursor, the
`EventRecordID` of a Windows log, the byte offset of `auth.log`) and reads on from where the
server last took them, so nothing is lost or sent twice. The server tells it **what to send**
(`GET /api/device/security/policy`): the identifiers (`sshd`, `sudo`, ...) and event ids (4625,
7045, ...) the enabled parsers read, so a busy log is filtered at the source and only the
records a parser can use leave the device (a parser without a `when` on them asks for the whole
log). Records go as columns (field names once), the body is gzipped (`Content-Encoding: gzip`,
signed over the compressed bytes, inflated by the server only after the signature checked out and
within a limit), and travels like the rest of the agent's communication: signed in both
directions over HTTPS.

Taking a collection is cheap: the server only puts it into its cache (compressed and encrypted
with the app key) and answers `202`. A job on the `security` queue parses, stores and scans it (the
queue worker of the Docker image; without a worker, `QUEUE_ENABLED=false`, it runs right after the
response), one collection of a device at a time, with retries.

What the agent collects (the *sources* of the rules):

| Source | Windows | Debian / Ubuntu |
|---|---|---|
| `software` | Programs and Features (machine and loaded user profiles) | dpkg (or rpm), snap, flatpak |
| `processes` | `Win32_Process` with the user and command line (the agent's own processes left out) | `/proc` |
| `listening` | `Get-NetTCPConnection` / `Get-NetUDPEndpoint` with the process | `ss -tulpn` |
| `startup` | Run / RunOnce keys, Startup folders, scheduled tasks outside `\Microsoft\` | cron (system and users), units in `/etc/systemd/system`, `rc.local`, desktop autostart |
| `admins` | members of Administrators | uid 0, groups sudo / wheel / admin |
| `posture` | firewall, antivirus (Security Center / Defender), BitLocker, Secure Boot, RDP and NLA, SMBv1, UAC, Guest, auto logon, days since the last update | firewall (ufw, firewalld, iptables / nftables), LUKS, Secure Boot, `sshd -T` (root login, passwords), unattended-upgrades, days since the last apt upgrade |
| `sqlserver` | local SQL Server instances (agents 1.18.0+, see [Compliance policies](#compliance-policies)) | — |
| logs (raw, since the previous collection) | `windows.security` (1102, 4625, 4648, 4697, 4698, 4720, 4722, 4724, 4726, 4728, 4732, 4740, 4756), `windows.system` (104, 7045), `windows.defender` (1006, 1116, 1117, 5001, 5010, 5012): `Time`, `Id`, `Provider`, `Level`, `Data` (EventData / UserData, names without spaces) | `linux.auth`: the auth / authpriv journal (or `auth.log`, `secure`): `Time`, `Identifier`, `Pid`, `Message` |

The built-in parsers ([`resources/security/parsers.json`](../src/laravel/resources/security/parsers.json))
make the events `failed_logon` (sshd, 4625), `sudo_failed`, `account_created` (useradd, 4720),
`admin_added` (usermod / gpasswd to sudo, wheel or admin, 4732 to Administrators),
`account_locked` (4740), `task_created` (4698), `log_cleared` (1102, 104), `service_installed`
(7045), `malware_detected` (1006, 1116) and `protection_disabled` (5001, 5010, 5012). A parser of
your own (**Add parser** on the **Rules** tab, tried on a sample line before it is saved):

```json
{
    "kind": "parser",
    "key": "custom.su-failed",
    "name": "su: wrong password",
    "source": "linux.auth",
    "when": {"field": "Identifier", "op": "eq", "value": "su"},
    "pattern": "^FAILED SU \\(to (?<target>\\S+)\\) (?<user>\\S+) on",
    "event": {"type": "su_failed", "user": "{user}", "source": "{target}", "message": "{user} failed su to {target}"}
}
```

`when` takes the conditions of detection rules on the record, `pattern` a regular expression with
named groups (on `Message`, or on `field`); `event` fills `type`, `user`, `source`, `message` and
`count` with `{group}` or `{Field}` placeholders (`{Data.IpAddress|Data.WorkstationName}` takes the
first that is set, `-` counts as not set). The first parser that matches a record makes its event;
a detection rule with `"source": "events"` and `{"field": "Type", "op": "eq", "value": "su_failed"}`
then alerts on it.

Events are grouped by type, user and source (*25 failed sign-ins of administrator from
203.0.113.7*) and kept 30 days on the **Events** tab. Findings of the inventory are resolved when the
next inventory no longer matches; acknowledging one keeps it quiet (no alert) until it is gone.
Findings of events stay open until they are acknowledged. The device shows a **Security** tab with
its findings and inventory, and an alert from medium severity; the **Security findings** alert type
of [Notifications](alerts.md#notifications-and-alerts) sends high and critical ones.

About 40 built-in rules come from
[`resources/security/rules.json`](../src/laravel/resources/security/rules.json): remote access tools,
attack and cracking tools, miners, end-of-life software, WinRAR / 7-Zip / PuTTY with known
exploited vulnerabilities, encoded PowerShell, download cradles, reverse shells, credential dumping,
programs running from temporary folders, clear-text services and open databases, suspicious startup
items, firewall / antivirus / encryption / SMBv1 / UAC / RDP / SSH settings, brute force, cleared
logs and new administrators. They follow the file with every update of the server; system admins
switch them off on the **Rules** tab or copy one to change it. **Add rule** takes a rule in JSON,
checks it while you type and shows what it would find on a device before it is saved:

```json
{
    "key": "custom.chrome-outdated",
    "name": "Outdated Chrome",
    "severity": "medium",
    "platform": "windows",
    "source": "software",
    "when": {"all": [
        {"field": "Name", "op": "starts_with", "value": "google chrome"},
        {"field": "Version", "op": "version_lt", "value": "120"}
    ]},
    "message": "{Name} {Version} is outdated",
    "remediation": "Update Chrome."
}
```

**Rules from a git repository.** Besides the bundled rules, a job takes rules from a public git
repository once a day (and with **Update now**): set `MDM_SECURITY_FEED_URL` (a GitHub address such
as `https://github.com/you/security-rules`, or the base address of the raw files of another host,
HTTPS only) and optionally `MDM_SECURITY_FEED_REF` (branch or tag, default `main`). The repository
has a `manifest.json` with a `version` and the SHA-256 of every file (`{"version": "2026.10.1",
"files": {"rules/apps/remote.json": "<sha256>", "parsers/linux/su.json": "<sha256>"}}`),
`rules/**/*.json` (detection rules) and `parsers/**/*.json` (parsers), each file one definition or a
list. Everything is fetched and checked against the manifest first and applied in one go: an
unreachable repository, a file that does not match its hash or a manifest that is not one changes
nothing. Invalid definitions are skipped and listed; rules of the feed that disappeared from it are
removed. What you switched on or off stays, a rule you added yourself keeps its definition (custom
over feed over bundled), and a bundled rule the feed replaced comes back when it is dropped.

- `severity`: `critical`, `high`, `medium`, `low` or `info`; `platform`: `any`, `windows` or `linux`.
- `when`: a condition `{"field", "op", "value"}`, or `{"all": [...]}`, `{"any": [...]}`,
  `{"not": {...}}` (up to 6 levels, 64 conditions). Operators: `eq`, `ne`, `contains`,
  `not_contains`, `starts_with`, `ends_with`, `matches`, `not_matches` (regular expression),
  `in`, `not_in` (a list), `gt`, `gte`, `lt`, `lte`, `version_lt`, `version_lte`, `version_gt`,
  `version_gte` (`1.2.3`, `2:1.2-3ubuntu1`), `exists`, `empty`, `true`, `false`. Texts are compared
  without case; an unknown value is neither `true` nor `false`.
- `message`: the finding, with `{Field}` placeholders of the item.
- `threshold`: one finding for the device when at least *n* items match (`{count}` and `{items}` in
  the message), e.g. more than 3 administrators.
- Each matching item is its own finding, told apart by the key fields of its source (software:
  name and source; processes: name, path and command line; ports: protocol, address and port; ...).

## Compliance policies

The **Compliance** tab of **Security** measures the devices against benchmarks and baselines (the CIS
Benchmarks, your own standards). A **policy** is a list of **checks**; every check looks at a source of
the inventory, like a detection rule, and gives each device one status:

| Status | When |
|---|---|
| **Fail** | `fail` holds on an item |
| **Warn** | `warn` holds (and `fail` does not) |
| **Manual** | it cannot be told from what the agent reports: `manual` holds, or a field the check needs is not reported (`requires`, by default every field of `fail` and `warn`) |
| **Pass** | none of the above |
| **Not applicable** | `applies` does not hold, the source has no items (no SQL Server on the device), or the policy is for another platform |

Each item of the source is judged on its own and the check takes the worst status (an instance with
`xp_cmdshell` on fails the check even when the other instances pass). The tab shows each policy with
the share of passing checks among the ones that could be told (fail, warn, pass) and, per check, the
devices that do not pass with since when; the **Security** tab of a device lists its results with the
remediation of what fails.

Policies come with the rules feed (`policies/**/*.json`, listed in `manifest.json` like rules and
parsers: one policy with its checks per file, or a list) and system admins add their own with **Add
policy** (JSON, checked while typing and tried on a device). Like rules, a policy of the feed is switched
on and off and copied, not edited, and one of your own keeps its definition when the feed has the same
key. The results are evaluated with every inventory and whenever policies change.

```json
{
    "key": "cis-mssql-2022",
    "name": "CIS Microsoft SQL Server 2019 / 2022 Benchmark",
    "version": "1.1.0",
    "platform": "windows",
    "source": "sqlserver",
    "manual": {"field": "Error", "op": "exists"},
    "checks": [
        {
            "id": "Sql.XpCmdshellDisabled",
            "name": "xp_cmdshell is disabled",
            "reference": "CIS 2.15",
            "severity": "critical",
            "fail": {"field": "XpCmdshell", "op": "ne", "value": 0},
            "message": "{Instance}: xp_cmdshell is {XpCmdshell}",
            "remediation": "EXEC sp_configure 'xp_cmdshell', 0; RECONFIGURE;"
        },
        {
            "id": "Sql.RemoteAdminConnectionsDisabled",
            "name": "Remote admin connections are off",
            "reference": "CIS 2.7",
            "severity": "medium",
            "applies": {"field": "Clustered", "op": "false"},
            "fail": {"field": "RemoteAdminConnections", "op": "ne", "value": 0}
        }
    ]
}
```

- Policy: `key`, `name`, `description`, `version`, `platform`, `source` (the default of its checks),
  `applies` and `manual` (conditions for every check), `checks` (up to 300).
- Check: `id` (letters, digits, `.`, `-`, `_`), `name`, `severity`, `reference`, `description`,
  `remediation`, `source`, `applies`, `manual`, `fail`, `warn` (at least one of them), `requires`,
  `message` (`{Field}` placeholders of the failing item). Conditions and operators are the ones of
  detection rules.

### SQL Server

On Windows, agents 1.18.0+ report every local SQL Server instance (`sqlserver`, one item per instance),
once the server says it takes that source. The agent signs in to each instance with **Windows
authentication as its own account** (`NT AUTHORITY\SYSTEM`): no password or connection string is kept
on the device or the server, and nothing is changed on the instance. Since SQL Server 2012 that login is
not a sysadmin, so give it read access once on each instance:

```sql
USE master;
GRANT VIEW SERVER STATE, VIEW ANY DEFINITION, VIEW ANY DATABASE TO [NT AUTHORITY\SYSTEM];
USE msdb;
CREATE USER [NT AUTHORITY\SYSTEM] FOR LOGIN [NT AUTHORITY\SYSTEM];
GRANT SELECT ON dbo.backupset TO [NT AUTHORITY\SYSTEM];
```

What the login may not read is not reported, and the checks that need it are **Manual**; an instance
that is stopped or refuses the sign-in has `Error` (and a policy with `"manual": {"field": "Error", "op":
"exists"}` turns all its checks Manual). The fields:

| Field | What |
|---|---|
| `Instance`, `Version`, `Edition`, `Clustered`, `Error` | the instance (`Clustered`: a failover cluster instance) |
| `AdHocDistributedQueries`, `ClrEnabled`, `CrossDbOwnershipChaining`, `DatabaseMailXps`, `OleAutomationProcedures`, `RemoteAccess`, `RemoteAdminConnections`, `ScanForStartupProcs`, `XpCmdshell` | `value_in_use` of the server configuration options |
| `TrustworthyCount`, `TrustworthyDatabases` | databases with TRUSTWORTHY on (msdb left out) |
| `SaEnabled`, `SaName` | the login with SID `0x01` |
| `WindowsAuthOnly` | false in mixed authentication mode |
| `SysadminCount`, `SysadminLogins` | enabled sysadmin logins without `NT SERVICE\*`, `##...##` and `NT AUTHORITY\SYSTEM` |
| `WeakPolicyCount`, `WeakPolicyLogins` | enabled SQL logins without CHECK_POLICY or CHECK_EXPIRATION |
| `NoFullBackupCount`, `NoFullBackupDatabases` | online user databases without a full backup in 24 hours |
| `NoLogBackupCount`, `NoLogBackupDatabases` | FULL / BULK_LOGGED user databases without a log backup in an hour |
| `NoCheckDbCount`, `NoCheckDbDatabases` | user databases without a good CHECKDB in 7 days (not reported before SQL Server 2016 SP2) |
| `LinkedServerSaCount`, `LinkedServersSa` | linked servers that sign in as `sa` |
| `OwnedBySaCount`, `OwnedBySaDatabases` | user databases owned by `sa` |
| `ForceEncryption`, `AllConnectionsEncrypted`, `NoTdeCount`, `NoTdeDatabases` | Force Encryption (registry), whether every TCP connection is encrypted, user databases without TDE |
| `ErrorLogCount`, `LoginAuditLevel` | the number of error logs kept, login auditing (`None`, `Success`, `Failure`, `Both`) |

The lists (`...Databases`, `...Logins`) name at most 20; the counts are exact. The CIS Microsoft SQL
Server 2019 / 2022 Benchmark is a policy of the [detection-policy](https://github.com/PanoptiPulse/detection-policy) feed.
