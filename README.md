# WeeklyTargetsBundle

A [Kimai](https://www.kimai.org) plugin for fixed weekly time targets, both goals ("at least 10 hours a week on
paper X") and limits ("at most 5 hours a week of leisure"):

- a **Weekly target** field (a duration such as `10:00`; empty means no target) and a **Kind of weekly target** field
  (**at least** / **at most**) on every project and every customer (Edit project / Edit customer; the target is also
  a column in their lists and exports);
- a **Weekly targets** report (Reporting menu), one week at a time with previous / next week buttons: target,
  tracked time, difference and the percentage of the target, per project and per customer; below, the time tracked
  without any target, all the week's time by customer (targets or not), and the week's total. Durations are hours
  and minutes (`HH:MM`).

- optionally, a **weekly summary** pushed to [ntfy](https://ntfy.sh), e.g. every Monday for the week just ended (see
  below).

Kimai's own time budgets are either for the whole lifetime of a project or monthly; this plugin adds the weekly view.

## How it counts

- **At least** (≥) is a goal: shown red until the tracked time reaches it, green after. **At most** (≤) is a limit:
  green while the tracked time stays within it, red once it is exceeded. Difference = tracked − target.
- A **project** target compares with the time tracked on that project.
- A **customer** target compares with the time tracked on all the customer's projects, those without a target of
  their own included (shown in grey under the customer).
- A customer without its own target gets a subtotal of its projects' targets when they are all of the same kind.
- The **total** adds up the "at least" targets only, i.e. the planned time: the customer target where there is one,
  otherwise its projects' "at least" targets. Limits are not added up.
- Time on projects without a target, of customers without a target, is listed apart, so it never makes a goal look
  reached.
- Tracked time is the logged-in user's own time: every timesheet that starts within the week (the week starts on
  the user's first weekday setting). A running timer counts up to now.
- Targets of hidden projects or customers are ignored: hide a finished project to drop its target.

## Weekly summary on ntfy

```sh
bin/console weekly-targets:notify --user=<username> [--week=YYYY-MM-DD] [--dry-run] --env=prod
```

pushes one week of one user, by default the previous week, to an ntfy topic. The title is "Kimai: HH:MM tracked,
N of M targets met", with ✅ when all are met and ⚠️ otherwise (📊 and no target count when no target is set). The
message is Markdown (ntfy renders it in its apps): the week first, so that a collapsed notification shows it; then
a table of all the time tracked in the week by customer, longest first, with its share of the week, and the week's
total; then a table of the targets (✅ / ❌ and name, tracked of target, difference), the planned "at least" total
and the time without a target; at the bottom the date and time it was sent. Tables have three columns at most, since
some apps give every column the same width. Durations are `HH:MM`. Nothing is sent when the week has
neither targets nor tracked time. The texts follow the user's language (English and Italian are included);
`--dry-run` prints the notification instead of sending it.

Settings, as environment variables of Kimai (e.g. in `.env.local`, or `environment:` of the Docker container):

| Variable | |
|---|---|
| `WEEKLY_TARGETS_NTFY_URL` | ntfy server, e.g. `https://ntfy.example.com` (required) |
| `WEEKLY_TARGETS_NTFY_TOPIC` | topic, default `kimai-weekly-targets` |
| `WEEKLY_TARGETS_NTFY_TOKEN` | access token, if the topic needs one |
| `WEEKLY_TARGETS_KIMAI_URL` | Kimai's address, e.g. `https://kimai.example.com`: tapping the notification opens the week's report |

Run it once a week, e.g. on Monday morning with cron:

```sh
0 8 * * 1  cd /path/to/kimai && bin/console weekly-targets:notify --user=alice --env=prod
```

or, with the Docker image, from the host's scheduler:
`docker exec -u www-data kimai /opt/kimai/bin/console weekly-targets:notify --user=alice --env=prod`.

## Requirements

- Kimai 2. Tested with 2.69.0.
- The report needs the permissions `view_reporting` and `report:project` (System › Roles), which admins have.

## Installation

The plugin directory must be called `WeeklyTargetsBundle`:

```sh
cd /path/to/kimai/var/plugins
git clone https://github.com/6801318d8d/WeeklyTargetsBundle.git
cd /path/to/kimai
bin/console kimai:reload --env=prod
```

With the official Docker image (replace `kimai` with the name of your container):

```sh
git clone https://github.com/6801318d8d/WeeklyTargetsBundle.git
docker cp WeeklyTargetsBundle kimai:/opt/kimai/var/plugins/
docker exec kimai chown -R www-data:www-data /opt/kimai/var/plugins/WeeklyTargetsBundle
docker exec -u www-data kimai /opt/kimai/bin/console kimai:reload --env=prod
```

The plugin creates no database tables: the targets are stored as meta fields of projects and customers
(`kimai2_projects_meta`, `kimai2_customers_meta`; names `weekly_target`, the value in seconds, and
`weekly_target_type`, `at_least` or `at_most`; a missing kind means "at least"). Removing the plugin keeps them; they
come back if it is installed again.

## License

MIT, see [LICENSE](LICENSE).
