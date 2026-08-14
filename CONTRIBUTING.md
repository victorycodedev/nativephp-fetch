# Contributing

Thank you for your interest in improving NativePHP Fetch. Bug fixes,
documentation improvements, tests, and sensible refinements are welcome.

Please open a GitHub Issue before implementing a significant feature or public
API change so the design and cross-platform impact can be discussed first.

## Contribution workflow

1. Fork the repository.
2. Create a focused branch for the change.
3. Make the smallest coherent change.
4. Add or update tests where appropriate. Bug fixes should include a regression test.
5. Run the relevant validation and test commands.
6. Commit the change with a clear message.
7. Push the branch to your fork.
8. Open a Pull Request describing the change and how it was tested.

## Project guidelines

- Preserve current NativePHP Mobile v4 conventions.
- Maintain Android and iOS behavior parity where applicable.
- Do not casually change `Fetch.Start`, `Fetch.Download`, `Fetch.Cancel`, their
  native class paths, or existing event names and payload contracts.
- Preserve backwards compatibility where reasonable.
- Keep networking asynchronous and preserve cancellation and exactly-once
  terminal-event guarantees.
- Do not load large uploads or downloads fully into memory.
- Avoid unnecessary native dependencies. Do not force Kotlin, Gradle, Swift,
  or other toolchain versions that conflict with the NativePHP host app.
- Follow the existing architecture instead of introducing duplicate abstractions.
- Test Kotlin or Swift changes in a generated NativePHP application when possible.

## Validation

Install dependencies with `composer install`, then run the commands relevant to
your change:

```bash
composer validate --strict
composer dump-autoload -o
composer test
node --test resources/js/fetch.test.js
```

For JavaScript module syntax checks:

```bash
node --check resources/js/fetch.js
node --check resources/js/PendingRequest.js
node --check resources/js/bridge.js
node --check resources/js/request-id.js
node --check resources/js/retry.js
```

Native changes also need compilation and runtime testing in a generated
NativePHP Mobile v4 application. In the Pull Request, describe any Android and
iOS emulator, simulator, or physical-device testing you performed. It is fine
to mark platforms as not applicable when the change cannot affect them.
