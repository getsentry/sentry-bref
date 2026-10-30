# Changelog
## 0.1.0

### New Features ✨

- (ci) Run CI on release branches by @Litarnus in [#10](https://github.com/getsentry/sentry-bref/pull/10)

### Documentation 📚

- Update Sentry logo in README with dark mode support by @sentry-junior in [#9](https://github.com/getsentry/sentry-bref/pull/9)

### Other

- Bump getsentry/craft from 2.31.2 to 3.0.0 by @dependabot in [#8](https://github.com/getsentry/sentry-bref/pull/8)
- Bump actions/create-github-app-token from 3.2.0 to 3.3.0 by @dependabot in [#7](https://github.com/getsentry/sentry-bref/pull/7)
- Release with craft by @mnapoli in [#6](https://github.com/getsentry/sentry-bref/pull/6)
- Add a README by @mnapoli in [#4](https://github.com/getsentry/sentry-bref/pull/4)
- Add a breadcrumb for each AWS SDK call by @mnapoli in [#3](https://github.com/getsentry/sentry-bref/pull/3)
- Set up coding standards and static analysis by @mnapoli in [#2](https://github.com/getsentry/sentry-bref/pull/2)
- Require Bref 3.0.13 by @mnapoli in [#1](https://github.com/getsentry/sentry-bref/pull/1)
- Test without Laravel and Symfony, and with Symfony 6.4 by @mnapoli in [518b22b5](https://github.com/getsentry/sentry-bref/commit/518b22b56b52960831041fc6266f7b3c696b6951)
- Remove the Symfony 4 compatibility code by @mnapoli in [2c300cfb](https://github.com/getsentry/sentry-bref/commit/2c300cfb609f2bec6c2eb40b04cda90f868e32bf)
- Make the Laravel and Symfony dependencies optional by @mnapoli in [ca1c00f4](https://github.com/getsentry/sentry-bref/commit/ca1c00f41d2c0eaaa89a996e9264b0b2c3972625)
- Set the status of the warm restart transaction by @mnapoli in [34a4713f](https://github.com/getsentry/sentry-bref/commit/34a4713f1e7b0ccdfb0d8453edb25294f3c7f614)
- Record whether the invocation includes a cold start by @mnapoli in [a7ab0563](https://github.com/getsentry/sentry-bref/commit/a7ab056381a0a6a106aa5f0e40187ad23250efa3)
- Set the status of the invocation transaction by @mnapoli in [f5e11a8b](https://github.com/getsentry/sentry-bref/commit/f5e11a8b7abe00a1f553f5dc426abcdc2a220370)
- Follow Sentry conventions for the invocation transaction by @mnapoli in [8ae409e1](https://github.com/getsentry/sentry-bref/commit/8ae409e1b6fd280a5b106a2824acbbb4c1100427)
- Keep the first Octane request from being dropped by Sentry by @mnapoli in [e94f8467](https://github.com/getsentry/sentry-bref/commit/e94f84674be33fde7a9a72ab436bbb2b87327427)
- Add the cold start to the transaction of Laravel jobs by @mnapoli in [77644ce0](https://github.com/getsentry/sentry-bref/commit/77644ce097b7de5b98f6914e5722d0262282950f)
- Let sentry-laravel create the transaction of Laravel jobs and Octane requests by @mnapoli in [7ec404f4](https://github.com/getsentry/sentry-bref/commit/7ec404f43c1681231f9f979ced95817892ea6e7b)
- Fix cold starts inflated by proactive initializations by @mnapoli in [e8131873](https://github.com/getsentry/sentry-bref/commit/e813187321e9ee58032e0875f2e713271f741c4d)
- Require Bref 3.0.4 by @mnapoli in [dd1e92cb](https://github.com/getsentry/sentry-bref/commit/dd1e92cb231c3a06496a3b9e764bc6eeb77445da)
- Log internal errors in Bref's log format by @mnapoli in [8d59d88c](https://github.com/getsentry/sentry-bref/commit/8d59d88c4f54f5cc06a1f8de7ef56f85dec50ef7)
- Only subscribe to Bref events in the Bref runtime by @mnapoli in [5cf06be9](https://github.com/getsentry/sentry-bref/commit/5cf06be9476e8e348830468461255991b205a2f7)
- Never let the AWS SDK tracing break the AWS call by @mnapoli in [f97f90fa](https://github.com/getsentry/sentry-bref/commit/f97f90faa47ff5f11f087a602871ae175119bce6)
- Never let the framework middlewares break the request by @mnapoli in [d4396801](https://github.com/getsentry/sentry-bref/commit/d43968010a61f51d374e8b316e35ae0c3f6e11b9)
- Never let the Bref hooks fail the invocation or the runtime by @mnapoli in [4042bd7f](https://github.com/getsentry/sentry-bref/commit/4042bd7fb8748a2b748e162ff1f4e43f49035068)

_Plus 14 more_

