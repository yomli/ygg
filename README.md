# Ygg
🗂️  File explorer for git projects hosted on your own server.

Your own read-only minimal GitHub! [Gitlist](https://gitlist.org/) is cool, but it requires `git`, and therefore a *lot* of memory. This one do many of the features of GitHub|list, in less than 40 KB of a single file.

A demo is [here](https://dev.yom.li/projects/ygg), so you can see Ygg showing it's own source code (and that's meta).

## Features

- File explorer
- Releases tab
- Download the entire project or just one file
- Raw view
- Fuzzy search to find a file
- RSS feed
- Customizable

## Requirements

- Apache with `mod_rewrite`
- PHP 7.2+
- PHP `zip` extension
- PHP `json` extension

## Install

Just drop the `index.php` file in a directory containing a bunch of folders.

```
wget https://raw.githubusercontent.com/yomli/ygg/master/index.php
```

In its default state, Ygg will use the `master` folder as its index (the "Code" tab on GitHub) if it exists, else it will show the content of the `releases` folder. Configure Ygg if you want others folders to be browseable. Then open Ygg in your favorite browser so it can create the necessary `.htaccess`.

## Configuration

You may have to edit some variables placed in the first lines of `index.php`. Alternatively, you can just drop a `config.json` file in the same directory, and it will override the default values:

```json
{
	"title": "My cool project",
	"nav": {
		"browseable": {
			"Code": "src",
			"Releases": "releases"
		},
		"links": {
			"Help": "docs",
			"Fork me here": "https://github.com/name/repo"
		}
	}
}
```

### Parsedown

By default, README files are rendered in plaintext. You can change that by editing the path to a [Parsedown.php](https://parsedown.org/) file:

```
'parsedown' => '/assets/php/Parsedown.php' // Path to the Parsedown.php parser (for readme parsing)
```

### Syntax highlighter

By default, text files are rendered in plaintext. You can change that by editing the variables of the `syntax_highlighter` array:
```
'syntax_highlighter' => [
	'css' => '/assets/css/Prism.css', // Path to the css of the syntax highlighter you use
	'js' => '/assets/js/Prism.js', // Path to the js of the syntax highlighter you use
	'strip_numbers' => false // Do not show the numbers (ie, if using a Prism.js' plugin)
]	
```

### Custom favicon

Just place a `favicon.ico` file in your directory, and set `custom_favicon` to `true`.

### Alert

Any text contained in the `alert.txt` file in your directory will be displayed on top of the file explorer. It's useful if you want to show the latest commit gotten by a webhook or a RSS parser script on your server.

### Symbolic linked

Since it's just one file, you can symlink it into any project directory, using for each one a custom `config.json`.

## License

Licensed under the terms of the MIT license. See the [LICENSE](LICENSE) file for license rights and limitations.

## Credits

- Inspired by [Minixed](https://github.com/lorenzos/Minixed).
- SVG icons from [IconSVG](https://iconsvg.xyz/), [Icomoon](https://icomoon.io/) and [Octicons](https://octicons.github.com/), converted using https://yoksel.github.io/url-encoder/
- Favicon by [smalllikeart](https://www.flaticon.com/authors/smalllikeart) from [www.flaticon.com](https://www.flaticon.com/)
