# alfred-productive-workflow

An [Alfred](https://alfred.app/) workflow to quickly interact with your [Productive.io](https://productive.io) organization.

## Installation

Download the workflow from the [latest release](https://github.com/studiometa/alfred-productive-workflow/releases/) and install it. You will be prompted to configure 2 variables:

- `PRODUCTIVE_ORG_ID`: the ID of your organization on Productive, you can find it in the URL when logging in `https://app.productive.io/<PRODUCTIVE_ORG_ID>-foo/...`
- `PRODUCTIVE_AUTH_TOKEN`: a personal access token for the API, generate one in Settings → API integrations

Once this is done, open Alfred and use the `pp` keyword to list all tasks from your organization.

### Troubleshooting

You may need to allow the execution of the static PHP CLI shipped with the Workflow. To do so, right click on the workflow in Aflred and choose "Open in Finder", then, if you are on a M1 or greater, right on the `bin/php-8.2.13-cli-macos-arm64` (otherwise use the one ending with `x86_64`) file and choose `Open with → Terminal`. You should be prompted by macOS to indicate if you trust this executable or not, choose as you prefer (if you refuse, the workflow will not work), then quit the Terminal app.

## Usage

The following commands are available:

| Keyword | Action |
|-|-|
| `ppt` | Get all tasks |
| `ppp` | Get all projects |
| `ppd` | Get all deals |
| `ppc` | Get all companies |
| `ppg` | Get all people (`g` for `gens` which means `people` in French) |
| `pps` | Get all services |

## Credits

Thanks to [crazywhalecc/static-php-cli](https://github.com/crazywhalecc/static-php-cli) for the static PHP executables and to [brandlabs/productiveio](https://github.com/brandlabs/productiveio) for the [Productive.io](https://productive.io) SDK

## To-do

- [ ] Merge results for a given resource that have the same ID
- [ ] Filter results from the script to be able to use the `rerun` feature and reload the list when it is updated
- [ ] Clean log files periodically (daily ?)
