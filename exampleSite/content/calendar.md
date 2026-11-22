---
title: "Calendar"
subtitle: "What's on at Alpha Church"
date: 2026-11-21T09:00:00+00:00
draft: false
---

Our calendar is kept up to date automatically — what you see here is live
from our Google Calendar.

{{< calendar >}}

The calendar above uses the zero-configuration `embed` service (Google's own
iframe). To get a calendar styled to match the theme, with month and agenda
views, switch `[params.calendar]` to `service = "google"` and add an API key —
see SETUP.md ("Calendar") for instructions.

You can override the view and height per page:

```
{{</* calendar view="listMonth" height="auto" */>}}
```
