# [1.5.0](https://github.com/ProjectMakersDE/horizOn-simpleServer/compare/v1.4.0...v1.5.0) (2026-10-23)


### Bug Fixes

* **achievements:** bind legacy messages to original achievement key ([7106d7d](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/7106d7dc77b89eada047665fb73337acd246a356))
* **achievements:** preserve explicit null reward rejection ([16e4ad8](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/16e4ad8268a3c6057e701379deb0a503a3d76cac))
* **chat:** expose explicit hosted-only capability boundary ([60a2d66](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/60a2d6641f59c16e139ee87cb0f87aa991cadda2))
* **chat:** isolate database-free CLI test from PHP module setup ([21586bc](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/21586bc29bd2c5cef1160e8ba14e9d46bb67b05d))
* finish SQLite transactions on supported PHP versions (TASK-1004) ([3a3a2d6](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/3a3a2d6d4b3e249253258c5ddf453a076a59e2f8))
* **gift-codes:** inspect SQLite through an existing read-only database ([4762af3](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/4762af3bed329bad21e339f2d2524ac5b3b647d2))
* **gift-codes:** keep retired campaign counter identities reserved ([0416bc7](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/0416bc74eb3bb2797aba66ba7917db3529dc9bf5))
* **gift-codes:** reject redundant UUID aliases without warnings ([ee3f97d](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/ee3f97d6ac1c52f12fd1afe7b289b0ce78313580))
* **inbox:** verify legacy value receipts before claiming and retain compact guards ([fe6c18b](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/fe6c18b2f041b4b34c72c0d48eb387e4b33aec6d))
* **json:** keep large string tokenization fail closed ([3c8c85f](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/3c8c85f25665d80ab795e6388cac721616a30120))
* **marketplace:** honor operator quotas and batch retained reads ([00127fe](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/00127fe3dd667d74396206314522539e324e59b1))
* **marketplace:** keep operation replay metadata on listings ([a2f6a43](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/a2f6a4355c65086d9aa09869482033f76deafec0))
* **marketplace:** probe retained operations in index checks ([854ecd8](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/854ecd8748e07c201159568cca7d3e149c6ef03e))
* **matches:** preserve oversized SQLite completions ([a8c822b](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/a8c822bd759c2cd6c76bf18e14e21f36aa79de78))
* **matches:** reserve exact player completion frame ([34196a3](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/34196a3fef295bd3599b68962438bf82e0919b09))
* **player-values:** accept configured multi-value mutations ([e73eb77](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/e73eb7788a6f3d313574c9b06ce6c66e415893c1))
* **player-values:** share current catalog and offer read locks ([8719121](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/8719121bac7d339f3a6b6440fdf8a24e57e0d24f))
* **player-values:** use current catalog in active MySQL transactions ([5102bfe](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/5102bfe0c1865f5f63b601076fa87d1651050714))
* **readme:** name the simpleServer cloud save limit as a self-hosting value and point to the plan limits of horizOn Cloud (TASK-1046) ([759abf1](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/759abf105bdfbecc7c5e037f5570209732d2a967))
* **shared-objects:** bind cold recovery to the sealed source plan ([10b5e55](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/10b5e5569867c578e8fd95a41768b72bea57db34))
* verify supported PHP runtimes before Develop merges ([d807d6f](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/d807d6f0e14fa96d46e4848257dd384f4c1c8849))


### Features

* **achievements:** atomically unlock progress and retain inbox rewards ([13108db](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/13108dbb6c8f13ec9fafe354e81d03d4107aaab3))
* **achievements:** store compact unlocks and grant rewards atomically ([6b3941f](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/6b3941fca94d7091fbc56d87ae6d7b76b68ec4a3))
* add atomic player values with SQLite and MySQL parity (TASK-1004) ([80f0b69](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/80f0b693995833697dcaf28bc51dafd6fab8b03f))
* add atomic statistics REST parity for SQLite and MySQL ([990d3d4](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/990d3d450ee29f07f529ca3b4ef3dcfc6ac7543d))
* add bounded asynchronous matches with atomic turn notices ([54a999d](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/54a999d85b51c4ec13b4781263ca1b9202ff7c17))
* **friends:** add atomic session-bound relationship REST APIs ([62c9f2d](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/62c9f2d597ba01fc4694784bb0ae0f7a242c82c5))
* **friends:** compact SQL relations and honor operator quotas ([d1f7970](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/d1f79704a63a28ac7987dbdcb683f0089ee67e21))
* **gift-codes:** atomically redeem configured player value rewards ([845811a](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/845811aa72f864b7c3a5dbe59e88ee29990b5056))
* **gift-codes:** persist V2 sequence guards and verified source migration ([846636f](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/846636f3eecbc7b7cf41b58ad50aa3f840706aaa))
* **groups:** add transactional self-hosted REST parity ([0209b71](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/0209b7163eb0c19a4cc313ef705bb4120bfbcb49))
* **groups:** compact SQL quota state and configurable group policy ([5124e6a](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/5124e6ab19cf36c0aced1735a95cd647303ef937))
* **inbox:** add bounded REST inbox with atomic value claims ([377d448](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/377d448ea86e8db7170be6568c29dcbf8bb70cc8))
* **inbox:** store compact source references and stable broadcast statuses ([ac726b3](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/ac726b3a43a3d743905d31fc7ba9584714ef3bba))
* **items:** add bounded inventory and atomic paid grants ([d80ce38](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/d80ce38ac5e2acf0fff856658277c0d983f519c1))
* **items:** share immutable bases and store instance deltas ([3214cd5](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/3214cd5e2fb651506cbd66108cac6f62d3fd725a))
* **marketplace:** preserve original items through atomic trades and returns ([9726488](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/97264887f511bb6d9e54ca5f994766db636a9a34))
* **matches:** compact state and separate retention quotas ([536352b](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/536352bfd5ef511c7ad3b0b8eb541ec2ad209187))
* **player-values:** compact SQL state and honor operator quotas ([92706f3](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/92706f33f65513c5d46775f8e232533829d60969))
* **realtime:** report explicit Cloud-only capability (TASK-995) ([4e96a00](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/4e96a00a3cb53a53f09412210b3f11c5b3a5f7de))
* **shared-objects:** add bounded self-hosted object storage ([f4ded77](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/f4ded77dbba2baa4e43d1f0e619d120ad26eab33))
* **shared-objects:** compact SQL storage and honor operator limits ([f1f532b](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/f1f532b59ea192c91c682235a22e307d0ca69de3))
* **statistics:** store compact counters with configurable definitions ([#21](https://github.com/ProjectMakersDE/horizOn-simpleServer/issues/21)) ([fd867f8](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/fd867f8c605790be79097bdda4b5e0dc0af99632))

# [1.4.0](https://github.com/ProjectMakersDE/horizOn-simpleServer/compare/v1.3.1...v1.4.0) (2026-10-11)


### Bug Fixes

* **ci:** test PHP compatibility before release promotion ([34e1bce](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/34e1bce782f5f9febeedcf9b53f32c5676f9b7dc))
* **gift-codes:** bind redemption to the player session ([26cd3c9](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/26cd3c98433a28b65718e1a8e2c9250433b47530))
* **gift-codes:** require the player session for every redemption ([51c96ed](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/51c96edcb5ccaebd5cae5ecf7b0ccf569bc2fd5d))
* **simpleserver:** restore remote config filtering and input hardening ([0bed142](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/0bed14284a718ecc932d10b69ae12da0df96ac96))


### Features

* **player-profile:** player profile, cosmetics catalog and gift code grants ([7765d61](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/7765d61ec470946b88f08503509916d3eec89901))

## [1.3.1](https://github.com/ProjectMakersDE/horizOn-simpleServer/compare/v1.3.0...v1.3.1) (2026-09-28)


### Bug Fixes

* **apple-signin:** drop PHP 8 str_ends_with for PHP 7.4 support ([a32fb0e](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/a32fb0e24de6a7e043172d45d56d7ca1ed30112a))

# [1.3.0](https://github.com/ProjectMakersDE/horizOn-simpleServer/compare/v1.2.0...v1.3.0) (2026-06-24)


### Features

* **localization:** add localization read endpoints and table ([7947656](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/7947656609bdc4b5a287b10c2ca4e031db68d82a))

# [1.2.0](https://github.com/ProjectMakersDE/horizOn-simpleServer/compare/v1.1.0...v1.2.0) (2026-04-20)


### Features

* **apple-signin:** apple sign-in support for self-hosted instances ([aa8983c](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/aa8983c5c76248810003765f943e01cb9849d6af))

# [1.1.0](https://github.com/ProjectMakersDE/horizOn-simpleServer/compare/v1.0.1...v1.1.0) (2026-04-11)


### Features

* add email sending feature with template-based SMTP delivery ([ef36aca](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/ef36acab691fad26799ba938cb0fd60c1dfb19ab))

## [1.0.1](https://github.com/ProjectMakersDE/horizOn-simpleServer/compare/v1.0.0...v1.0.1) (2026-03-26)


### Bug Fixes

* move commit command to parent workspace root ([a951844](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/a95184434be9c9b66e12c5863697735eb8a3c0bf))

# 1.0.0 (2026-02-22)


### Features

* add .env config parser ([2a25a77](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/2a25a77217ba1e1f19f73cf94f8b89aa3d815d53))
* add API key auth and per-IP rate limiting ([61afa81](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/61afa81c7520f68543b8a5dbe37bfc6887bb9d5f))
* add database abstraction with SQLite/MySQL migrations ([b8b7b1a](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/b8b7b1a6269e15e6765b80ed42c6b1887c73fba9))
* add entry point with routing, server configs, and controller stubs ([44b138f](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/44b138ff3f2f21cc1d2a7087c75a7a31a5b3f829))
* add Request and Response core classes ([4d7a5cd](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/4d7a5cd8786b96871f24e90800909b0b53b3dc27))
* add semantic-release pipeline and commit command ([3552d3e](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/3552d3e673044df2d6c7b07555bb2a2bcd9a545e))
* add URL pattern-matching router with CORS support ([e457b64](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/e457b641b87f2f881e5bdfe2c32b6b19de6a292c))
* implement cloud save controller (save, load) ([56e8fa7](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/56e8fa71b2e5c21a22ce60dcae867212dec9fb00))
* implement crash reporting controller with group upsert and auto-regression ([39e6a9d](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/39e6a9d7303b515d09b89da555398b7887e7d7c1))
* implement gift codes controller (validate, redeem) ([c6c6e66](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/c6c6e66c41d87fd18d5323572ef1e34c11004179))
* implement leaderboard controller (submit, top, rank, around) ([f618977](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/f6189779c84826088265d728852b0ab58540ed34))
* implement news controller ([3f0eedf](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/3f0eedf6379b61725b4c14b5399530f26bf0f388))
* implement remote config controller (get, all) ([934bb4b](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/934bb4b9b44e5bb4c9128052315a0522d9614d8e))
* implement user feedback controller ([2dfa557](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/2dfa5576d20faf7ff68e70c3df5d7d08508db42b))
* implement user logs controller ([a4f2514](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/a4f25148329be1202c7d1a866bff3aa641f37a50))
* implement user management controller (anonymous auth) ([334628d](https://github.com/ProjectMakersDE/horizOn-simpleServer/commit/334628df7ba25ff3160603df64e5daf239d3c70d))
