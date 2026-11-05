<p align="center">
  <img src="docs/banner.png" alt="Resource Advisor for Pelican Panel" />
</p>

<p align="center">
  <a href="https://github.com/MaitreAntho/pelican-resource-advisor/releases/latest"><img src="https://img.shields.io/github/v/release/MaitreAntho/pelican-resource-advisor?style=flat-square&color=3b82f6" alt="Latest release" /></a>
  <img src="https://img.shields.io/badge/Pelican-plugin-3b82f6?style=flat-square" alt="Pelican plugin" />
  <img src="https://img.shields.io/badge/any%20game%20or%20bot-supported-22c55e?style=flat-square" alt="Any game or bot" />
  <a href="LICENSE"><img src="https://img.shields.io/github/license/MaitreAntho/pelican-resource-advisor?style=flat-square&color=22c55e" alt="MIT license" /></a>
</p>

<p align="center">
  <b>Stop guessing how much RAM, disk and CPU your servers need.</b><br />
  Resource Advisor watches every server of your <a href="https://pelican.dev">Pelican Panel</a> and tells you which ones need more, and which ones could do with less.
</p>

---

## ✨ Features

### 🚦 Advice for every server
A **Resource Advisor** page in the admin area lists every server with its advice: **Needs more**, **Could do with less**, **Right size** or **Still measuring**. One filter shows only the servers that need attention.

<p align="center"><img src="docs/servers.png" alt="Every server with its advice" width="90%" /></p>

### 🧠 Smart about memory
- A server above **90 %** of its memory at peak, or **killed for lack of memory** (reported by Wings), needs more.
- **Java servers** (Minecraft, Project Zomboid...) keep all the memory they are given, so a full graph means nothing: they are judged on out of memory kills only. No false alarms.

### 💾 Disk forecast
The 7-day trend tells you **how many days are left** before the disk is full. Under 14 days, you get the advice before your users get the error.

### 📈 7-day usage graphs
Hourly peaks for memory, disk and CPU, with the current limit. See the evening rush, and how fast the disk fills.

<p align="center"><img src="docs/usage.png" alt="7-day usage graphs" width="90%" /></p>

### 🖱️ Apply in one click
The advised limits are filled in for you, change them if needed. **Every other limit is kept** (databases, allocations, backups) and the node applies the change right away.

<p align="center"><img src="docs/apply.png" alt="Apply the new limits" width="90%" /></p>

### 🪶 Light and safe
- One request per node every 5 minutes, whatever the number of servers. Hourly summaries, kept 35 days.
- Lower limits are only suggested after a full week of data, with a safe margin.
- Admins only: users never see this page.
- Works with **SQLite, MySQL, MariaDB and PostgreSQL**. English and French included.

---

## 📦 Installation

**From the admin area (easiest)**
1. Open **Admin → Plugins** and click **Import from URL**.
2. Paste this link and confirm:
   ```
   https://github.com/MaitreAntho/pelican-resource-advisor/releases/latest/download/resource-advisor.zip
   ```
3. Click **Install** next to **Resource Advisor**.

**Manually**: download `resource-advisor.zip` from the [latest release](https://github.com/MaitreAntho/pelican-resource-advisor/releases/latest), extract it into the `plugins` folder of your panel (the folder must be named `resource-advisor`), then install it from **Admin → Plugins**.

The first measures arrive within 5 minutes. Advice appears after 12 hours of use for memory and CPU, and 24 hours for disk.

## ⚙️ Requirements

- Pelican Panel 1.0 or newer, with its scheduler running (it is, in the official Docker image and with the standard cron).
- Wings crash detection enabled (the default), so out of memory kills are counted.

## 🔧 Settings

The thresholds can be changed with environment variables in your panel's `.env`:

| Variable | Default | Meaning |
| --- | --- | --- |
| `RESOURCE_ADVISOR_WINDOW_DAYS` | `7` | Days of usage the advice looks at |
| `RESOURCE_ADVISOR_RETENTION_DAYS` | `35` | Days of samples kept |
| `RESOURCE_ADVISOR_INCREASE_THRESHOLD` | `0.9` | Share of the limit that means "needs more" |
| `RESOURCE_ADVISOR_DECREASE_THRESHOLD` | `0.4` | Peak share under which memory "could do with less" |
| `RESOURCE_ADVISOR_DISK_DAYS_WARNING` | `14` | Days before the disk is full that trigger the advice |
| `RESOURCE_ADVISOR_SUGGEST_DECREASE` | `true` | Also suggest lower limits |

## ❓ FAQ

**Will it change my servers by itself?**
No. It only gives advice. Limits change when an administrator clicks **Apply**.

**Why does my Minecraft server show 95 % memory and "Right size"?**
Java reserves the memory it is given, whatever the game needs. The plugin only asks for more when the server is actually killed for lack of memory.

**Does it slow down the panel or the nodes?**
No. It asks each node once every 5 minutes for the stats Wings already keeps, and stores one row per server and per hour.

## 🤝 Contributing

Issues and pull requests are welcome, especially translations in `lang/`.

## 📄 License

[MIT](LICENSE) © MaitreAntho
