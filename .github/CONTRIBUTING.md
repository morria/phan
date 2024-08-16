[![Build Status](https://travis-ci.org/phan/phan.svg?branch=master)](https://travis-ci.org/phan/phan) [![Gitter](https://badges.gitter.im/phan/phan.svg)](https://gitter.im/phan/phan?utm_source=badge&utm_medium=badge&utm_campaign=pr-badge)

Phan attempts to adhere to the [PSR-2](http://www.php-fig.org/psr/psr-2/) and [PSR-12](https://www.php-fig.org/psr/psr-12/) style guides. All files should use

```php
<?php

declare(strict_types=1);
```

- [Phan's phpcs.xml](https://github.com/phan/phan/blob/master/phpcs.xml) can
  be used with [`phpcs` and `phpcbf`](https://github.com/squizlabs/PHP_CodeSniffer) to adhere to the style guide.
- `internal/phpcbf` will automatically fix any style issues in your changes.
  Alternately, `phpcbf.phar --standard=phpcs.xml ...paths` can be used

Pull requests that come [with tests](../tests/README.md) are great.

Issues that come with simplified failing code are great, but don't let that stop you from submitting issues if you can't get a simple case.

[Frequently Asked Questions (Wiki)](https://github.com/phan/phan/wiki/Frequently-Asked-Questions) contains answers to some common questions/bug reports about Phan.
