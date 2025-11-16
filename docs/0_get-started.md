---
title: Get Started
---

## What is ThumbHash?

ThumbHash is an image placeholder algorithm. Placeholders are represented by small ∼28 bytes hashes such as `1QcSHQRnh493V4dIh4eXh1h4kJUI`.
A demo and more information can be found on the [website](https://evanw.github.io/thumbhash/).

This plugin adds ThumbHash support to Kirby, allowing you to implement UX improvements such as progressive image loading or content-aware spoiler images like Mastodon.

Under the hood, the heavy work gets done by a PHP implementation of ThumbHash by [SRWieZ](https://github.com/SRWieZ): [SRWieZ/thumbhash](https://github.com/SRWieZ/thumbhash)

## Requirements

- Kirby 5.0+
- PHP 8.3+
- `gd` extension (required by Kirby)
- Optional: `imagick` extension (for better performance / quality)

## Installation

**Composer is the recommended way to install Kirby ThumbHash.** Run the following command in your terminal:

```sh
composer require tobimori/kirby-thumbhash
```

Alternatively, you can download and copy this repository to `/site/plugins/thumbhash`, or apply this repository as Git submodule.
