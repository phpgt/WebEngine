# Contributing to PHP.GT

Firstly, thank you! If you're reading this, you're probably interested in contributing to a PHP.Gt repository in some way. Thank you for showing interest and taking the time to read this document. 

WebEngine and the wider PHP.GT ecosystem are community-driven projects. Contributions of all sizes are welcome, including bug reports, documentation improvements, feature ideas, and pull requests.

## Before you start

PHP.GT is split across multiple repositories. Choosing the right repository for your issue or contribution helps maintainers respond faster.

WebEngine is the core project for building web applications. It handles responsibilities such as the request/response lifecycle, page logic classes, and `go` functions.

Other important repositories across the project include:

- [Dom](https://php.gt/dom): modern wrapper around PHP's `DOMDocument`.
- [DomTemplate](https://php.gt/domtemplate): templating, data binding, and custom elements.
- [Input](https://php.gt/input): typed request input handling instead of direct superglobal use.
- [Database](https://php.gt/database): SQL organisation and execution patterns for WebEngine apps.

See the full list of repositories at [github.com/orgs/phpgt/repositories](https://github.com/orgs/phpgt/repositories).

## Ways to contribute

### Ask a question

For usage questions, feel free to open a new issue at the relevant repository. It will be labelled as "question" and answered as soon as possible.

### Report a bug

Please open an issue in the relevant repository and include:

- A clear title and summary.
- Exact reproduction steps.
- Expected behaviour and actual behaviour.
- A minimal code sample where possible.
- Screenshots or logs when relevant.

Helpful environment details:

- Operating system.
- PHP version.
- Web server and version (Apache, Nginx, etc.), if applicable.
- Runtime context (native, VM, container).
- Dependency versions tested, and whether changing versions affects the issue.

### Suggest an enhancement

Feature ideas are welcome. Please include:

- A clear problem statement.
- Why the enhancement would be useful.
- Example usage (code, mockup, or workflow).
- Similar behaviour in other tools, if relevant.

### Improve documentation

Documentation is maintained in each repository's GitHub Wiki and published to `php.gt`.

Wiki pages can be edited by any registered GitHub user. Changes are reviewed over time; there is no formal PR flow for wiki edits.

### Sponsor development

PHP.GT is open-source and free. For sponsorship enquiries, contact [sponsors@php.gt](mailto:sponsors@php.gt).

## Development workflow

### Prerequisites

- PHP `>=8.4`
- Composer

### Setup

1. Fork the repository.
2. Clone your fork.
3. Install dependencies:

```bash
composer install
```

### Make your change

1. Create a topic branch from `master`. It's recommended to prefix the branch name with the issue number, like `123-my-feature`.
2. Add or update tests for the behaviour you change.
3. Keep scope focused to a single bugfix or feature where possible.
4. Update docs when public behaviour changes.

### Run checks

Run the relevant tests before opening a PR:

```bash
vendor/bin/phpunit
```

If you are changing a specific area, running a targeted subset locally is fine, but full test validation may be requested during review.

## Pull request guidelines

When opening a PR, please include:

- What changed.
- Why it changed.
- How to test it.
- Any related issues (for example `Closes #123`).

Additional expectations:

- Keep commits meaningful and reviewable.
- Prefer small, focused PRs over large mixed changes.
- Draft PRs are welcome when you want early feedback.

## Coding style and testing expectations

- Follow the PHP.GT style guide: https://php.gt/styleguide
- Prioritise test coverage for changed behaviour.
- TDD is encouraged where it helps, but practical, high-signal tests are the main goal.

## Security

Do not report security vulnerabilities in public issues.

Please use this repository's security policy and private reporting flow:

- [SECURITY.md](./SECURITY.md)

## Getting started issues

If you are looking for a place to start:

- [Good first issues within PHP.GT][good-first-issues]
- [Help wanted issues within PHP.GT][help-wanted-issues]

Maintainers aim to support new contributors, especially on onboarding-friendly issues.

[good-first-issues]: https://github.com/search?l=&q=org%3Aphpgt+type%3Aissue+is%3Aopen+label%3A%22good+first+issue%22&ref=advsearch&type=Issues&utf8=%E2%9C%93
[help-wanted-issues]: https://github.com/search?l=&q=org%3Aphpgt+type%3Aissue+is%3Aopen+label%3A%22help-wanted%22&ref=advsearch&type=Issues&utf8=%E2%9C%93
