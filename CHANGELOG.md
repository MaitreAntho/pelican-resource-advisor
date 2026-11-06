# Changelog

## 1.1.0

New
- Node overview above the table: reserved, used at peak, left to hand out and to reclaim, for memory, disk and CPU. Nodes handing out more than they have are shown in red.
- Alerts: out of memory kills right away, and a daily summary of the servers that need more. On Discord (webhook) and/or as panel notifications to the root administrators.
- Settings page (Admin → Plugins → Settings) for the thresholds and the alerts, with a test button. The `.env` variables still work.
- Ignore a server for 7 days, 30 days or until stopped: it leaves the counters, the filter and the alerts.
- Usage graphs over 24 hours, 7 days or 30 days, with the hourly average, the limit history and a red mark at each out of memory kill. Hours without measures now leave a gap at the right place.
- Sort by advice, export as CSV, 25 servers per page, and the filter is kept in the address.
- The Apply form shows the space left on the node, and warns when the node goes over its capacity.
- Its own permissions in the role editor (Admin → Roles → Resource Advisor): **View** opens the page, **Update** applies limits and ignores servers. Root admins always have both.

Fixed
- After a limit change, the advice only uses the hours measured with the new limit. Before, an out of memory kill at the old limit kept asking for more after every increase.
- A server with low usage but less than a week of data was shown as "Right size". It now shows "Measuring", with the date the lower limit will be confirmed.
- Administrators whose role is limited to some nodes now only see the servers and nodes they are allowed to see.
- The page computes the advice once for all servers and caches it, instead of twice per server on every refresh.

Upgrade: non-root administrators need the new **View** permission (and **Update** to apply limits) to see the page. Before, any role allowed to list servers could open it.

Upgrade: the update adds two small database changes, applied automatically. Hours measured before 1.1.0 have no limit recorded and are kept as they are.

## 1.0.1

- Usage graphs: hover (or touch) a graph to see the hour and the value at that point.

## 1.0.0

First release.

- Records the memory, disk and CPU use of every server every 5 minutes, with one request per node.
- Advice on 7 days of usage: needs more, could do with less, right size or still measuring.
- Out of memory kills reported by Wings are counted, and Java servers are judged on them only.
- Disk forecast: days left before the disk is full at the current pace.
- Usage graphs per server, with the current limits.
- Apply the advised limits in one click, keeping every other limit of the server.
- Works with SQLite, MySQL, MariaDB and PostgreSQL.
- English and French translations.
