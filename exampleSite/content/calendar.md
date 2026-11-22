---
title: "Calendar"
subtitle: "What's on at Alpha Church"
date: 2026-11-21T09:00:00+00:00
draft: false
---

This page demonstrates the theme's live calendar. Once you configure
`[params.calendar]` in your site's `hugo.toml` with a public Google Calendar
ID (see SETUP.md, "Calendar"), events appear here automatically — fetched
live in the visitor's browser, so the calendar is always up to date without
rebuilding the site.

{{< calendar >}}

Two services are available: `embed` (Google's own iframe, no API key needed)
and `google` (a FullCalendar widget styled to match the theme, with month and
agenda views; needs a free API key restricted to the Google Calendar API).

You can override the view and height per page:

```
{{</* calendar view="listMonth" height="auto" */>}}
```
