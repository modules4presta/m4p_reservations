# Contributing

Thanks for taking the time to help.

## Reporting bugs

Open an issue and include the PrestaShop version, the PHP version, the module version and the steps
to reproduce. Logs from `var/logs/` help a lot.

## Pull requests

1. Branch off `main` (`fix/...`, `feat/...`, `chore/...`).
2. Keep the change focused; unrelated cleanups belong in their own pull request.
3. Keep English as the source language in the code. New strings go through
   `$this->trans('Text', [], 'Modules.<Modulename>.Admin')`, and the Polish catalogue in
   `translations/pl-PL/` is updated in the same pull request.
4. Bump `$this->version` and both `config.xml` and `config_pl.xml` when the change is user visible,
   and add a `CHANGELOG.md` entry.
5. Describe what changed and how you tested it on a real shop.

Comments and documentation are in English. Keep them short: what the code does is visible in the
code, so comment the non-obvious parts only.

## License

By contributing you agree that your work is published under the MIT license of this repository.
