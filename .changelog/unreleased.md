### Changed

- Replace the filesystem cache with an indexed SQLite cache. [[#35]]
- Publish cache refreshes atomically and preserve the last successful data when a refresh fails. [[#35]]
- Coordinate concurrent background refreshes with per-resource locks. [[#35]]

[#35]: https://github.com/studiometa/alfred-productive-workflow/pull/35
