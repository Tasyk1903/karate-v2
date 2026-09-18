# KarateRating: карта проекта и правила переиспользования

Актуализация: 09.09.2026. Это единый технический справочник для разработки web v2 и мобильного приложения тренера. Он объединяет 20 прежних тематических документов без потери правил доступа, бизнес-логики, операций с файлами и ограничений проверки.

**Что ещё делать:** [единый scope](remaining-scope.md). Здесь описано устройство и принятое поведение; открытые задачи ведутся только в scope. Сводные правила работы агента находятся в [AGENTS.md](../../AGENTS.md).

17.09.2026 владелец сообщил о завершении приёмки ученика; отдельный Student scope удалён по его запросу. Карта реализации — [Student mobile](#student-mobile), в том же Flutter-приложении, что Coach. Общие внешние зависимости оплаты, почты, распространения и push остаются в [едином scope](remaining-scope.md); сообщение о приёмке интерфейса не означает настройку этих сервисов.

В тот же день владелец подтвердил приёмку тренера и судьи, рабочих маршрутов организатора/секретаря, сеток/ката/видео, контрольных выгрузок и внешнего вида на телефоне. Соответствующие пункты удалены из scope на основании его проверки, без заявления о новом автоматическом прогоне. Приёмка мастера отдельно не подтверждена; внешние зависимости push, оплаты, писем и ссылок установки остаются открытыми.

Судья и мастер реализованы в том же **Flutter-приложении**, по прямому решению владельца 09.09.2026. [Карта реализации и старого поведения](#staff-mobile), [оставшиеся решения и приёмка](judge-master-scope.md). Judge работает со своей колонкой балльной ката, Master с назначенными ему оплаченными учебными работами. Web-кабинеты этих ролей не являются целевой платформой; не расширять их права до Coach/Organization ради подключения экрана.

<a id="start"></a>
## Как пользоваться в новой задаче

1. Прочитать AGENTS.md, этот вводный раздел, карту переиспользования и соответствующие открытые пункты scope.
2. Найти доменный раздел по оглавлению, открыть указанные контроллеры, сервисы, клиент и тесты. Документ помогает найти код, но не заменяет чтение его актуальной версии.
3. При переносе поведения сверить старый action вместе с policy/query/visibility: наличие класса не означает доступность роли.
4. Расширять действующий сервис или компонент. Не создавать второй расчёт медалей, подбора, документов, прав, платежа или отдельную модель сессии для нового экрана.
5. После изменения обновить соответствующий раздел здесь. Закрыть в scope только действительно выполненный и проверенный пункт; оставшуюся внешнюю приёмку не удалять.
6. Не создавать новый markdown-отчёт на каждый этап. Новые сведения добавлять в эти два документа, если пользователь явно не запросил отдельный артефакт.

Старые номера задач внутри доменных разделов описывают историю переноса. В едином scope добавлены префиксы ORG/MOB, поскольку TOUR, RATING и EXPORT в прежних аудитах имели разные значения. Упоминание старого ID в справочнике не означает, что задача снова открыта.

<a id="workspace"></a>
## Проекты и точки входа

Общий каталог: `/Users/artemyablochnyi/projects/karate`.

Git-репозиторий web v2/backend: `git@github.com:Tasyk1903/karate-v2.git`, ветка `main`, корень `karaterating-v2`. Старый проект и Flutter находятся вне этого репозитория. Рабочие `.env`, приватные ключи, дампы, пользовательские загрузки, зависимости и сборки исключены; в Git остаются только безопасные `.env.example` и `.env.docker.example`. SSH-ключ выбирается локальной Git-конфигурацией, не коммитится вместе с кодом.

| Проект | Назначение | Где начинать |
| --- | --- | --- |
| `karaterating` | Старый Laravel/Filament, только референс | `app/Filament/`, `app/Policies/`, `app/Models/User.php`, `app/Http/Controllers/`, `resources/views/pdf/` |
| `karaterating-v2` | Общий Laravel backend, web Vue SPA, mobile JSON API | [routes/web.php](../routes/web.php), [routes/api.php](../routes/api.php), [bootstrap/app.php](../bootstrap/app.php) |
| `karaterating_trainer` | KumiteRating: общее Flutter-приложение Coach, Student, Judge, Master; ранее «Karate Rating Trainer» | [lib/main.dart](../../karaterating_trainer/lib/main.dart), [lib/app/karate_rating_app.dart](../../karaterating_trainer/lib/app/karate_rating_app.dart) |

С 17.09.2026 отображаемое название мобильного приложения — **KumiteRating** в обеих локалях: `AppStrings.appTitle`, iOS `CFBundleDisplayName/CFBundleName`, Android `android:label`. iOS permission descriptions используют то же название. Dart package/классы, bundle ID/applicationId, схема `karaterating://` и secure-storage keys сохранены: переименование не создаёт другое приложение и не требует очистки данных. Название сайта, реквизиты и серверное описание проекта этим изменением не переименованы. Для обновления подписи под иконкой нужна новая установленная сборка, hot reload недостаточен.

- Web: [App.vue](../resources/js/components/App.vue), [PanelLayout.vue](../resources/js/layouts/PanelLayout.vue), [страницы панели](../resources/js/pages/panel/). Не добавлять Filament/Inertia; Blade используется для оболочки SPA, писем и серверных PDF, не для рабочих экранов панели.
- API организатора/секретаря: [Panel-контроллеры](../app/Http/Controllers/Panel/); тренера: [Mobile-контроллеры](../app/Http/Controllers/Mobile/). Проверки доступа общих сущностей переиспользуются, но аудитории и capabilities не смешиваются.
- Backend-переводы: [lang](../lang/), локаль web/mobile: [PanelLocale](../app/Http/Middleware/PanelLocale.php). Vue: [resources/js/i18n](../resources/js/i18n/). Flutter: [AppLocale/AppStrings](../../karaterating_trainer/lib/l10n/app_locale.dart). Язык приложения сохраняется, передаётся через Accept-Language, используется JSON/multipart/download-запросами и безопасными ссылками регистрации/reset.
- Схема: [миграции](../database/migrations/), [модели](../app/Models/). Данные старого импорта могут содержать дубликаты, строковые ID в JSON и legacy-поля; не «чистить» их массово без отдельного согласования.
- Последний backend-прогон 09.09.2026 после S3: **247 тестов / 3213 assertions** (SQLite). Ранее в этот день: реальная конкуренция MySQL **1 тест / 20 assertions**, Flutter **69 тестов**, анализатор без замечаний, сборка Vue и **9 браузерных workflow** успешны. Браузерный API подменён; MySQL-тест выполнен в отдельной базе, не рабочей. Прежний PDF-прогон 12 файлов / 57 страниц остаётся датированным результатом экспортного этапа, не новым сравнением реальных данных.

<a id="reuse"></a>
## Что переиспользовать в первую очередь

| Задача | Каноническая реализация и правило |
| --- | --- |
| Ролевой доступ панели | [PanelAccess](../app/Services/PanelAccess.php), [PanelTournamentVisibility](../app/Services/Tournaments/PanelTournamentVisibility.php); скрытая кнопка не заменяет API-проверку |
| Закрытые документы/видео и S3 | [ProtectedMedia](../app/Services/ProtectedMedia.php) проверяет видимость/ссылки; [MediaStorage](../app/Services/MediaStorage.php) читает local/S3, выдаёт Range и логотипы PDF; private/no-store, без bearer в URL |
| Права/поля ученика | [StudentProfileAccess](../app/Services/Students/StudentProfileAccess.php), [TournamentProfileAccess](../app/Services/Account/TournamentProfileAccess.php) (запреты только владельцев активных турниров участника), [UpdateStudentProfile](../app/Services/Students/UpdateStudentProfile.php), [StudentDocumentStatus](../app/Services/Students/StudentDocumentStatus.php) |
| Права/поля собственного профиля тренера | [CoachProfileAccess](../app/Services/Account/CoachProfileAccess.php), [UpdateCoachProfile](../app/Services/Account/UpdateCoachProfile.php), [ProfileFieldValidation](../app/Services/Account/ProfileFieldValidation.php) |
| Судейская очередь, своя оценка и видео | [JudgeKataAccess](../app/Services/Tournaments/Kata/JudgeKataAccess.php), [JudgeTables](../app/Services/Tournaments/Kata/JudgeTables.php), общий KataScoreService/KataMutation; никаких отдельных расчётов Judge |
| Разборы мастера, назначение и профиль | [MasterReviews](../app/Services/Education/MasterReviews.php), [ReviewAssignment](../app/Services/Education/ReviewAssignment.php), [MasterProfile](../app/Services/Account/MasterProfile.php), [ProfileFiles](../app/Services/Account/ProfileFiles.php) |
| Согласия и безопасный текст | [Agreements](../app/Services/Account/Agreements.php), [MobileAgreements](../app/Services/Account/MobileAgreements.php), [SafeContent](../app/Services/Account/SafeContent.php), [NotificationContent](../app/Services/Account/NotificationContent.php) |
| Коды приглашений и email-подтверждение | [Team-сервисы](../app/Services/Team/), [RegistrationChallenge](../app/Services/Account/RegistrationChallenge.php); код не заменяет доказательство владения аккаунтом |
| Сроки соревнования | [TournamentLifecycle](../app/Services/Tournaments/TournamentLifecycle.php); активность панели до конца дня и открытая запись тренера различаются |
| Допуск/запись тренера | [CoachTournamentAccess](../app/Services/Tournaments/CoachTournamentAccess.php), [CoachTournamentEnrollment](../app/Services/Tournaments/CoachTournamentEnrollment.php); свой ученик + допуск + разрешение организации |
| Подбор/перенос заявки | [StudentTournamentListAssignmentService](../app/Services/Tournaments/StudentTournamentListAssignmentService.php), [TournamentAge](../app/Services/Tournaments/TournamentAge.php) (возраст на день комиссии), [ListCompatibility](../app/Services/Tournaments/ListCompatibility.php), [ListRankCriteria](../app/Services/Tournaments/ListRankCriteria.php), [TournamentApplications](../app/Services/Tournaments/TournamentApplications.php) |
| Результат боя и зависимости | [BracketMutation](../app/Services/Tournaments/BracketMutation.php), [BracketTopology](../app/Services/Tournaments/BracketTopology.php), [FightResultService](../app/Services/Tournaments/FightResultService.php), [RoundRobinResultService](../app/Services/Tournaments/RoundRobinResultService.php) |
| Оценки/финал/видео ката | [Services/Tournaments/Kata](../app/Services/Tournaments/Kata/); обновлять только целевую оценку, блокировать и проверять ревизию |
| Медали и история | [StudentMedalService](../app/Services/StudentMedalService.php), [StudentCompetitionHistory](../app/Services/Students/StudentCompetitionHistory.php); рейтинг за год отдельно в [RatingService](../app/Services/RatingService.php) |
| Платная онлайн-заявка | [OnlineKataPaymentService](../app/Services/Tournaments/OnlineKataPaymentService.php), [Payments](../app/Services/Tournaments/Payments/); долговечная запись, проверка у провайдера, идемпотентность |
| Лимит видео обоих кругов | [KataVideoUpload](../app/Services/Tournaments/Kata/KataVideoUpload.php), Flutter [VideoUpload](../../karaterating_trainer/lib/api/video_upload.dart); единый предел 100 MiB, приватная загрузка, прежний файл сохраняется при ошибке |
| Данные «О нас» и язык | Общие [RU](../lang/ru/about.php)/[EN](../lang/en/about.php) для PanelAboutController/MobileAboutController; AppStrings и Accept-Language, не локальная копия реквизитов или фиктивных счётчиков |
| PDF/XLSX и фоновые задачи | [TournamentDownloadService](../app/Services/Tournaments/TournamentDownloadService.php), [Services/Exports](../app/Services/Exports/), [Exports](../app/Exports/); пакет таблиц не заменять одной таблицей или протоколом |
| Изменения и аудит | [TeamActivity](../app/Services/Team/TeamActivity.php), доменные mutation-сервисы; actor, объект, old/new и контекст в одной транзакции |

### Общие компоненты клиента

- Vue: [ConfirmActionModal](../resources/js/components/panel/ConfirmActionModal.vue), [PaginationBar](../resources/js/components/panel/PaginationBar.vue), [AccountPager](../resources/js/components/panel/AccountPager.vue), [SearchableSelect](../resources/js/components/panel/SearchableSelect.vue), [FileDropzone](../resources/js/components/panel/FileDropzone.vue), [DocumentLightbox](../resources/js/components/panel/DocumentLightbox.vue).
- Адаптация web-панели: [PanelLayout](../resources/js/layouts/PanelLayout.vue), [panel-mobile.css](../resources/js/styles/panel-mobile.css), директивы [responsiveTable](../resources/js/directives/responsiveTable.js) и [responsiveTabs](../resources/js/directives/responsiveTabs.js). Подробности ниже; не создавать отдельную копию таблиц/API для телефона.
- Выбор участников/списков: [TournamentStudentPicker](../resources/js/components/panel/TournamentStudentPicker.vue), [TournamentOptionPicker](../resources/js/components/panel/TournamentOptionPicker.vue). Сохранять выбранные ID между страницами, не подменять серверный поиск фильтрацией первой страницы.
- Запросы кабинета: [useAccountRequest](../resources/js/composables/useAccountRequest.js). Стили панели: [app.css](../resources/css/app.css); иконки через существующую библиотеку/компонент, не новые SVG-копии.
- Flutter: [ApiClient](../../karaterating_trainer/lib/api/api_client.dart), [SessionHttpClient](../../karaterating_trainer/lib/api/session_http_client.dart), [AuthSession](../../karaterating_trainer/lib/auth/auth_session.dart), [SessionController](../../karaterating_trainer/lib/auth/session_controller.dart), [CoachShell](../../karaterating_trainer/lib/navigation/coach_shell.dart), [CoachBottomNav](../../karaterating_trainer/lib/navigation/coach_bottom_nav.dart).
- Мобильные файлы: [ProtectedVideoPlayer](../../karaterating_trainer/lib/media/protected_video_player.dart), [VideoUpload](../../karaterating_trainer/lib/api/video_upload.dart), [download_helper](../../karaterating_trainer/lib/files/download_helper.dart). Медиа ленты публичные; документы, учебные работы и онлайн-ката приватные.
- Мобильный выбор ученика: [TournamentStudentPicker](../../karaterating_trainer/lib/tournaments/tournament_student_picker.dart), используется также экзаменами. Уведомления: [NotificationCounter](../../karaterating_trainer/lib/notifications/notification_counter.dart), [NotificationButton](../../karaterating_trainer/lib/notifications/notification_button.dart). Темы: [lib/theme](../../karaterating_trainer/lib/theme/).

<a id="super-admin"></a>
## Лендинг и супер-администратор

Реализовано 17.09.2026 по отдельному запросу владельца. Гость на `/` получает Vue [LandingPage](../resources/js/pages/LandingPage.vue), вошедший пользователь перенаправляется в `/panel`, `super_admin` — в `/panel/admin/feed`. Вход вынесен на `/login`; `/panel/login` также показывает форму входа. Локали лендинга: [landing.js](../resources/js/i18n/landing.js), стили: [landing.css](../resources/js/styles/landing.css). App Store/Google Play берутся только из `config/mobile_app.php`; без настроенных HTTPS URL кнопки открывают статус публикации, а не вымышленный магазин. Общие [LandingStoreLinks](../resources/js/components/landing/LandingStoreLinks.vue) и `storeLinks.js` проверяют адреса; [LandingBrand](../resources/js/components/landing/LandingBrand.vue) обслуживает шапку/подвал. Контактный email приходит из существующего `about.contacts.email` через `/api/public/app-links`. FAQ/контакты/описание приложения открываются в native dialog; ссылки разделов работают как якоря, мобильная навигация сворачивается. Публичные договоры доступны через [PublicAgreementController](../app/Http/Controllers/PublicAgreementController.php), безопасный HTML, без закрытых данных пользователя.

<a id="landing-reference"></a>
### Дизайн лендинга по референсу

17.09.2026 предыдущий дизайн заменён по **новому** референсу `94fb61fd-a5ab-421a-af79-40cab00e39b3.png`. Порядок: hero с фронтальным ударом и показателями → четыре роли → тёмная полоса возможностей → турниры → экзамены/обучение/сообщество/дополнительные возможности (2×2) → горный блок «О нас» → скачивание приложения → подвал. Все подписи макета перенесены в RU/EN, бренд на лендинге — **Kumite Rating**, логотип — существующий `public/assets/auth/kr.jpg`. Это переименование лендинга, не bundle ID приложения или юридических реквизитов.

На 736 px сохраняется композиция референса; до 600 px роли/возможности становятся двумя колонками, подробные разделы одной. Типографика задаётся фиксированными размерами на breakpoints, без масштабирования шрифта через viewport. [LandingChecklist](../resources/js/components/landing/LandingChecklist.vue) общий для списков преимуществ и модалок. Кнопки карточек ученика/инструктора открывают установку, секретаря/организации — вход в web. Кнопки «Подробнее» открывают описание с переходом к установке/входу; защищённые каталоги гостю не открываются. Социальные иконки пока открывают контакты и подписаны соответствующим tooltip, а не ведут на выдуманные профили. Для прямых переходов нужны реальные адреса владельца.

Числа 3000+/50+/40+/4+ заданы владельцем (турниры/клубы/страны уменьшены 18.09.2026), не вычисляются из рабочей БД. Изображения телефонов — сгенерированные иллюстрации макета, не скриншоты текущего Flutter. Упоминания push и будущих учебных форматов перенесены из референса и не означают завершения их интеграции; перед публичным запуском проверить маркетинговые утверждения вместе с остаточным scope.

В блоке обучения «В разработке» оставлены только Kumite разбор и Kumite класс (RU/EN); Online Kata исключена из этой подписи по запросу владельца. Это изменение текста лендинга, не отключение онлайн-ката в приложении.

18.09.2026 по референсу `2026-09-18 12.31.48.jpg` все декоративные и встроенные в иллюстрации японские знаки заменены на единый вертикальный знак Kyokushinkai. `calligraphyAsset` в LandingPage использует чёрный прозрачный `kyokushinkai-symbol.webp`; CSS инвертирует его в белый на тёмных секциях без изменения формы. Семь иллюстраций получили имена `*-kyokushin.webp`, чтобы исключить старый кэш; старые файлы больше не подключаются. Замена включает кимоно, грамоту, настенные полотна, заставку и маленькие логотипы телефонов/уведомления. Логотип KR в шапке/подвале сохранён. Год copyright фиксирован **2024** в RU/EN по прямому запросу владельца, не вычисляется из текущей даты.

Bitmap-assets хранятся в [public/assets/landing](../public/assets/landing/), статически, не в пользовательском S3. Встроенный `image_gen`, без CLI/API-ключа, с новым референсом; готовый `mountain-path.webp` переиспользован. Рукописный шрифт Caveat скачан из официального Google Fonts и обслуживается локально, лицензия `fonts/OFL-Caveat.txt`; основной текст Arial/Helvetica. Иконки Lucide, бренды Font Awesome, отдельный bitmap пояса через [LandingBeltIcon](../resources/js/components/landing/LandingBeltIcon.vue). Задания генерации:

| Файл | Задание |
| --- | --- |
| `hero-unity-kyokushin.webp` | Hero-фотография мальчика: заменить только знак на груди на чёрный Kyokushinkai из нового референса, сохранить позу, лицо, ткань, освещение и композицию. |
| `tournament-overview-kyokushin.webp` | Сохранить монтаж телефона/планшета/спортсмена; заменить знаки в красном логотипе телефона, на рукаве и шлеме на Kyokushinkai соответствующего цвета. |
| `examination-belt-kyokushin.webp` | Сохранить фото пояса/кимоно/грамоты; заменить прежние столбцы и печать на один чёрный знак из референса с перспективой бумаги. |
| `learning-phone-kyokushin.webp` | Сохранить телефон и видео ката; заменить знаки на груди спортсмена и на настенном полотне на чёрный Kyokushinkai. |
| `community-phone-kyokushin.webp` | Сохранить ленту и групповое фото; заменить красный знак приложения, чёрные знаки на кимоно и белый знак настенного полотна. |
| `notification-phone-kyokushin.webp` | Сохранить телефон и уведомление; заменить знак внутри красного кружка уведомления на полный знак Kyokushinkai. |
| `download-phones-moscow-kyokushin.webp` | Сохранить пару телефонов и знаки Kyokushinkai; заменить только строку местоположения `Kyiv, Ukraine` на `Москва, Россия` на белом телефоне, сохранив шрифт, перспективу, интерфейс и прозрачность. Built-in image_gen, text-localization; исходник `download-phones-kyokushin.webp`. |
| `karate-belt.webp` | Плоская красная иконка завязанного пояса с двумя горизонтальными сторонами и диагональными концами, прозрачный фон; CSS mask окрашивает по контексту. |
| `kyokushinkai-symbol.webp` | Выделить большой чёрный вертикальный знак из белого центра нового референса на прозрачный фон, сохранить форму/пропорции, удалить весь интерфейс скриншота. |
| `mountain-path.webp` | Только фон нижнего баннера: тёмная монохромная горная долина, лесные скалы и туман, каменный пик в центре, тории справа, тёмная область слева; без людей, текста и интерфейса. |

PNG-оригиналы генерации оставлены в `$CODEX_HOME/generated_images`; проект использует WebP-копии с сохранённой прозрачностью. Перенос изображений в `public` не делает доступными документы пользователей.

Блок `.landing-download` использует `overflow: clip` вместе с фигурной границей: выступающая невидимая часть макета телефонов не увеличивает высоту документа за футером. `landing-workflow.cjs` проверяет отсутствие дополнительной прокрутки ниже футера и подключение макета с Москвой; реальные профили и их местоположение не изменяются.

Замена знаков выполнена встроенным `image_gen` в режиме точечного редактирования: для каждого изображения переданы исходный asset и новый пользовательский референс, с требованием сохранить остальной UI/фото. Регрессия в `landing-workflow.cjs` дополнительно проверяет фиксированный год 2024, единый источник трёх декоративных знаков и подключение всех семи обновлённых иллюстраций. Визуальное совпадение самой каллиграфии проверяется отдельно от DOM/assertions.

Проверка замены знаков 18.09.2026: Vite production build и `landing-workflow.cjs` прошли (16 сочетаний viewport/RU/EN, действия лендинга, год, новые assets и pixel-проверка прозрачности знака/телефонов). После исправления прозрачного фона изображения обучения повторный прогон прошёл; итоговые снимки 736 и 393 px просмотрены. API в браузерном тесте подменён; backend, рабочая БД и Flutter не менялись.

Проверка нового дизайна: Vite production build и [landing-workflow.cjs](../tests/ui/landing-workflow.cjs): RU/EN на 360/393/430/736/768/1024/1440/1920 px, наличие всех секций, логотип/бренд, загрузка изображений/шрифта, отсутствие горизонтального переполнения, FAQ/Escape, описания/якоря, документы/ошибки, контакты, оба состояния магазинов и вход. [admin-workflow.cjs](../tests/ui/admin-workflow.cjs) проверяет вход с мобильного лендинга и 84 адаптивных состояния админки. Снимки 736/1440/393 px просмотрены. API браузера подменён; backend/Flutter/БД в этом изменении не менялись. Прежние Pint/`AdminWorkspaceTest` (12 тестов / 173 assertions, SQLite) относятся к предыдущему этапу, не к новому прогону. Публикация приложения, точное совпадение исходных фотографий и соответствие маркетинговых чисел рабочей статистике этим не подтверждаются.

- **Доступ:** [SuperAdmin](../app/Http/Middleware/SuperAdmin.php), [routes/admin.php](../routes/admin.php). Только живая внутренняя роль `super_admin` через существующий `hasProjectRole`, включая `model_has_roles`; одноимённые `Admin`/`admin` не считаются эквивалентом. Public registration не назначает эту роль. В текущей локальной базе роль уже была; пароль/роли существующих пользователей не менялись. `PanelAccess.capabilities.super_admin` задаёт отдельное меню. При смешанной роли администратора согласия не вызывают циклическое перенаправление.
- **UI:** [AdminWorkspace](../resources/js/pages/admin/AdminWorkspace.vue) лениво подключается в App.vue. Отдельные Feed/Agreements/Activity страницы, общий AdminRecordsPage для простых справочников/организаций/категорий. `useAdminList` защищает от устаревших ответов, пагинирует, сохраняет выбор между страницами; AdminDialog использует native dialog с focus trap. Административные таблицы используют общую `v-responsive-table`. [admin.js](../resources/js/i18n/admin.js), [activity.js](../resources/js/i18n/activity.js), [admin.css](../resources/js/styles/admin.css) обслуживают RU/EN и обе темы.
- **Лента:** FeedController/CommentController читают все города/организации, включая публикации с удалённым автором. Изменение текста/удаление вложения, удаление поста, модерация комментариев/ответов с отдельной пагинацией. Переиспользуются FeedAccess/FeedPosts/FeedDiscussion: автор не меняется, транзакция и аудит атомарны, удаление медиа проверяет оставшиеся ссылки. Расширение прав ограничено `super_admin`, остальные правила видимости сохранены.
- **Обучение:** EducationController + [EducationVideos](../app/Services/Admin/EducationVideos.php) + существующий EducationCatalog. Ката аттестации, кихон, идо-гейко, ката соревнований: категории и видео, просмотр, загрузка/замена видео и обложки, одиночное/массовое удаление. Для разборов — категории и стоимость, как старый EducationKlassCategoryResource. Исходный old EducationKlassVideoResource для super_admin возвращал пустую выборку: выставление оценок или создание/оплата чужой работы администратору не открывались. Назначение мастеров остаётся отдельным решением scope. Непустая категория защищена от удаления. Загрузка видео до 100 MiB; обложка до 5 MiB, JPEG-кадр создаётся в браузере при поддерживаемом видео, также доступна ручная обложка. Файлы в private disk `protected`, выдача только после admin middleware через ProtectedMedia. Компенсация новой загрузки при ошибке, очистка старых файлов после commit через DeleteUnusedEducationMedia с проверкой всех защищённых ссылок и очередью повторов.
- **Договоры:** [Admin/AgreementController](../app/Http/Controllers/Admin/AgreementController.php), редактор [RichTextEditor](../resources/js/components/admin/RichTextEditor.vue) на Tiptap 3 (ленивый admin chunk). Три фиксированных типа/ID сохранены для совместимости consent/payment offer; удаления нет, как в старом EditAgreement. `description` — русский текст, `description_en` — английский. При сохранении обязательны оба непустых текста, SafeContent удаляет небезопасную разметку, version защищает от перезаписи чужих правок. Общий Agreements::content обслуживает web, Flutter и публичное чтение по локали. Version включает обе редакции, но для старых документов без EN сохраняет прежний хеш; новая редакция требует повторного согласия. Acceptance сохраняет именно показанный текст и фактическую локаль. Старые русские договоры не переводились автоматически: до заполнения EN используется RU, API сообщает `content_locale`, в редакторе отмечено отсутствие EN.
- **Организации:** OrganizationController создаёт/меняет только Organization: название, email, пароль, регион. Существующий код организации сохраняется, отсутствующий генерируется через OrganizationInvitations с действующим администратором в аудите. Смена пароля отзывает mobile/session/remember доступ. Одиночное/массовое soft-delete с подтверждением запрещено для связанной организации, текущего аккаунта и super_admin. Связанные ученики/турниры каскадно не удаляются.
- **Регионы/масштабы:** DirectoryController с фиксированным allowlist, поиск/пагинация/CRUD и подтверждаемое массовое удаление. Системные scale.slug/is_rating/sort_order не меняются редактированием названия; новый пользовательский масштаб не включается в рейтинг автоматически. Системные масштабы и справочники, используемые пользователями/турнирами (в том числе soft-deleted), защищены от удаления.
- **Журнал:** ActivityLogController, ActivitySubjects, AuditValues и прежний `activity_log`, без второго хранилища/плагина. Объект с названием/ID, автор, целевой пользователь, время, фильтры обоих пользователей по имени/фамилии/email/ID, даты в часовом поясе браузера, просмотр old/new. Имена объектов/пользователей загружаются пакетно. Новые TeamActivity записывают индексируемый `target_user_id`, секреты рекурсивно редактируются; старые записи поддерживают JSON context и Spatie `old/attributes`. Старые парольные поля не выдаются в ответе. Незаписанную ранее историю восстановить нельзя: UI честно сообщает об отсутствии сравнения. Удаления/редактирования журнала нет.

### Таблицы и навигация администратора

Обновление интерфейса 17.09.2026: общий заголовок раздела, компактные вкладки обучения, путь «дисциплина → категория» и отдельная кнопка назад. Категории открываются по свободному месту строки, названию или стрелке; видео по строке/кнопке просмотра. Организации и справочники открывают редактор, договоры редактор текста, журнал сравнение old/new. [rowAction.js](../resources/js/components/admin/rowAction.js) не перехватывает checkbox, кнопки, ссылки и выделение текста; кнопки остаются доступны с клавиатуры. Не делать всю строку вложенной кнопкой.

Переиспользовать [AdminSearch](../resources/js/components/admin/AdminSearch.vue) для поиска с иконкой и очисткой, [AdminPager](../resources/js/components/admin/AdminPager.vue) для диапазона записей и номеров страниц. `useAdminList.range` берётся из серверных `from/to`, при отсутствии метаданных выводится только общий счётчик. На время смены категории/поиска старые строки блокируются сразу, ещё до debounce; ответы устаревших запросов игнорируются. Выбранные ID сохраняются между страницами, сбрасываются при смене выборки; групповые действия вынесены в отдельную полосу выбора.

В [admin.css](../resources/js/styles/admin.css) выделены одинаковые размеры иконок действий, hover/selected строк, языковые статусы договоров, мобильные записи с отдельными email/кодом и dark native controls. Изменения ограничены `.admin-workspace`, не меняют остальные роли. Проверка: Vite build и расширенный `admin-workflow.cjs` с подменённым API (84 мобильных сочетания RU/EN и light/dark, desktop 1440 px, 11 страниц, диапазоны/активная страница, сохранение выбора, переход по свободной части строки, стрелка с Enter, возврат в категории, просмотр/редактирование видео, выравнивание иконок и отсутствие переполнения). Backend, права и рабочие данные этим UI-этапом не менялись.

Миграция `2026_09_17_210000_add_admin_content_and_activity_indexes` добавляет EN, locale acceptance и индексы/target журнала; применена отдельно локально без очистки БД. Существующий worker перезагружен через `queue:restart`, MySQL не перезапускался. Проверки: [AdminWorkspaceTest](../tests/Feature/AdminWorkspaceTest.php), [admin-workflow.cjs](../tests/ui/admin-workflow.cjs). Они покрывают прямые отказы ролям, pivot-роли, модерацию, версии/локаль и XSS договоров, коды организации, массовый rollback, приватные видео/компенсацию, аудит и пагинацию. Браузер использует подменённый API: рабочие данные, письма и S3-загрузки не изменяются. На рабочих данных отдельно выполнен только read-only smoke MySQL запросов журнала/организаций. Визуальная приёмка владельца и заполнение утверждённого EN-текста остаются в remaining-scope.

Итоговый backend-прогон этого этапа 17.09.2026: **336 тестов / 4494 assertions**, SQLite `:memory:` с пустым `DB_URL` и отдельным `APP_CONFIG_CACHE`. PHP Pint и Vite production build прошли. Playwright проверяет сценарии лендинга/админки и 84 сочетания раздела, RU/EN, light/dark и ширин 360/393/430 px; desktop/mobile снимки просмотрены. Это не тест реальной отправки писем, загрузки в S3 или физического устройства.

<a id="web-mobile"></a>
### Адаптивная web-панель организации и секретаря

Это Vue web v2, не Flutter-приложение тренера. Права и источники данных остаются общими с настольной панелью.

- До 1024 px PanelLayout заменяет sidebar нижней навигацией «Дашборд / Турниры / Команда / Меню». С 17.09.2026 меню раскрывается полноэкранным native dialog (`100dvh`, safe-area), перекрывая нижнюю навигацию. Шапка с крестиком и выход остаются видимыми, пользователь/роль и разрешённые разделы прокручиваются внутри `.panel-mobile-menu-content`. Закрытие кнопкой, Escape и выбором раздела; нажатие пустого пространства меню его не закрывает. Фон заблокирован, focus trap и возврат фокуса обеспечиваются dialog. Используются те же отфильтрованные `navItems`, а не отдельный список прав. Desktop sidebar не изменён.
- До 720 px таблицы с `v-responsive-table` становятся размеченными записями. Подписи берутся из актуального `thead`, обновляются при смене локали/данных; длинные значения и действия получают полную строку. Выбор, подтверждение веса, поля оценок, события и пагинация остаются в исходном DOM. Не дублировать данные вторым мобильным компонентом; не возвращать фиксированную высоту/скрытое переполнение ячеек. Для ката пять оценок располагаются вместе с отдельными подписями и итогами.
- `v-responsive-tabs` держит активную вкладку видимой, прокручивая только полосу вкладок, не всю страницу. PaginationBar оставляет на телефоне три числовых страницы плюс переходы к началу/концу и соседним страницам. В TemplatesPage стрелки вверх/вниз вызывают тот же workflow перестановки, что desktop drag; перестановка выполняется среди загруженных строк.
- Фильтры, модалки, карточки чемпионатов/турниров, команда, профили, экзамены, анкеты, рейтинг и настройки используют общий адаптивный слой `panel-mobile.css`. Поля ввода на телефоне имеют 16 px для предотвращения автоматического zoom iOS, подписи и данные остаются компактными. Встроенный горизонтальный скролл большой таблицы на промежуточной планшетной ширине допускается; страница целиком не должна выходить за viewport.
- Сетка TournamentDetailPage до 1024 px показывает один раунд с выбором стадии, стрелками и горизонтальным свайпом. Названия берутся из существующей глубины сетки; смена списка возвращает первый раунд. Бой за третье место расположен после полуфинальных боёв внутри этой стадии; на desktop сохранена общая сетка с третьим местом слева от финала. Результат по-прежнему открывается существующей модалкой, без нового расчёта исходов.
- [tests/ui/panel-mobile-workflow.cjs](../tests/ui/panel-mobile-workflow.cjs) проверяет dashboard, команду, шаблоны, настройки, рейтинг, чемпионаты, турниры, экзамены, участников/списки/сетки на 360/393/430/768/1024/1440 px. Комбинации: Organization/RU/light и Secretary/EN/dark; экзамены только разрешённой роли. Проверяются переполнение страницы/телефонных таблиц, высота содержимого ячеек, меню, выбор/свайп раунда, модалка результата и перестановка шаблонов. API полностью подменён, тест не обращается к реальным загрузкам, платежам или изменению рабочей БД. Физический Safari/Android и личная визуальная приёмка остаются в scope.
- Проверка полноэкранного меню дополнена 17.09.2026: точное совпадение границ dialog с viewport до 1024 px, высоты 900/360 px, внутренний скролл, видимость крестика/выхода, блокировка фона, закрытие крестиком/Escape и возврат фокуса. Проверка native dialog допускает промежуточный фокус browser chrome при Tab, но не переход в заблокированную страницу. Снимки RU/light и EN/dark проверены визуально; это браузер с подменённым API, не физический телефон.
- Список чемпионатов (`TournamentsPage`, `.tournament-grid`) до 1024 px показывает одну карточку в ряд; desktop сохраняет три колонки. Это адаптация web-панели: Flutter `ChampionshipsScreen` уже использует вертикальный список и не менялся. [championship-grid-workflow.cjs](../tests/ui/championship-grid-workflow.cjs) проверен 17.09.2026 с тремя карточками и длинными названиями на 360/393/430/768/1024/1440 px, RU/EN, light/dark: расположение карточек, отсутствие переполнения и переход в чемпионат. API подменён; Vite-сборка выполнена.
- Итоговый прогон 09.09.2026: Vite production build, `panel-mobile-workflow` и все 9 существующих UI workflow (`team`, `kata`, `fight`, `tournament-list`, `account`, `external-form`, `tournament-management`, `student-history`, `panel-export`) прошли. Снимки команды, участников, чемпионата, рейтинга и сетки проверены визуально. Backend/Flutter в этом этапе не менялись; их прежние тесты не выдаются за повторный прогон. Сборка применена на `http://127.0.0.1:8080`, Docker/MySQL перезапускать не требуется.
- Исправление профиля ученика 17.09.2026: [StudentDetailPage](../resources/js/pages/panel/StudentDetailPage.vue) группирует фото, реквизиты и подтверждение по документу, а не отдельными рядами общей сетки. Номер марки относится к `brand`, срок страховки к `insurance`, сведения последнего экзамена отделены. Legacy `documents.rows` сохраняется для других клиентов: именованные поля сопоставляются по label, только `includedInDocumentCheck` берётся из соответствующей колонки. На телефоне кумитэ/ката/рекорд идут одной колонкой; подпись года находится в заголовке без отрицательных отступов, медали переносятся внутри своих ячеек. Права и обработчики подтверждения/просмотра прежние. Расширен [student-history-workflow.cjs](../tests/ui/student-history-workflow.cjs): 360/393/430/768/1440 px, RU/EN, обе темы, длинные реквизиты, принадлежность полей/действий, отсутствие файла, read-only capability. API подменён, рабочая БД не изменяется; снимки проверены, личная повторная приёмка исправления выделена в scope.

<a id="staff-mobile"></a>
## Судья и мастер в общем Flutter-приложении

Реализовано 09.09.2026. Целевая платформа по запросу владельца: **нативные Flutter-экраны, не Vue и не WebView**. Открытые внешние вопросы находятся только в [judge-master-scope.md](judge-master-scope.md).

### Старые действия и принятые границы

| Роль / старый сценарий | Текущее поведение и отличия |
| --- | --- |
| Judge: создание аккаунта организатором, глобальная judge_position | Переиспользуется TeamController/форма команды. Публичного самоназначения Judge нет; позиция остаётся глобальной, не выдумывается новая таблица назначений по татами |
| Judge: активные балльные ката своей организации, созданные таблицы, поиск турнира/категории, татами, фильтр турнира | Отдельная пагинируемая очередь и picker турнира. Закрытые, чужие, удалённые чемпионаты, кумитэ и флажковая ката не открываются по прямому ID |
| Judge: предварительный этап/финал, номер и состав, своя колонка | Только назначенная из пяти колонок, включая очистку; чужие оценки, min/max/total, медали, итоговая таблица, редактирование номеров и генерация недоступны |
| Judge: изменение оценки после создания зависимых результатов | Исправлена опасная возможность инвалидировать финал через confirmed: предварительный этап закрыт при существующем финале, финал закрыт при существующих призёрах. Открывает этап действием управления Organization/Secretary, не Judge |
| Judge: online-видео участника и группы | Данные, клуб от тренера, нормализованный ранг, полная категория и приватное видео соответствующего круга. Первый круг не подставляется вместо отсутствующего финального видео; загрузка и документы ученика закрыты |
| Judge: прочие разделы и согласия | Рейтинг в старом коде явно исключён; Feed/UserAlert/About/спортивный профиль не подтверждены. Сейчас только рабочая очередь, минимальный read-only профиль и обязательные соглашения. Старый тупик consent gate без права Agreement устранён; CRUD соглашений не предоставлен |
| Judge: PDF и desktop/offline | Полный PDF закрыт, поскольку раскрывает скрытые оценки. Старый DesktopOfflineController позволял слишком широкие package/sync; его endpoints не перенесены, offline не заявляется как готовая функция |
| Master: оплаченные назначенные reviewer_id работы | Своя очередь, поиск, сортировка имени/новых работ, статусы и пагинация. Нет доступа к чужой или неоплаченной работе даже по ID; студент открывается как сведения работы, не закрытый профиль |
| Master: description, point, detail_point, recommendation, is_review | Черновик, публикация, изменение опубликованного и подтверждённый возврат в ожидание. Учебные оценки остаются текстом как в старых TextInput: максимум 100 символов; комментарии/рекомендации до 10000. Турнирные 0–10 не применяются. Новая обязательность полей не придумана |
| Master: старая замена исходного видео/категории до проверки | Небезопасное изменение оплаченного предмета услуги не перенесено: эти поля read-only. Нет create/pay/delete/bulk/force-delete/restore/reorder/replicate по избыточным старым permissions |
| Master: назначение | Сохраняется первый подходящий живой Master. Неоднозначные смешанные роли и external исключены. До оплаты назначение проверяется повторно; при отсутствии мастера платёж не начинается. При утрате мастера до callback платёж получает conflict/reviewer_unavailable, работа не объявляется выполненной; возврат не выполняется автоматически |
| Master: собственный Profile | Старые личные поля и пять документов сохранены; отмена документов тренеру на Master не распространяется. Клуб/роли/организация/подтверждение документов не редактируются. Вес ограничен активным участием; свой аккаунт можно удалить с паролем, если нет ожидающих оплаченных разборов |
| Master: Rating/About/Agreement | Используются общий рейтинг, фильтры, About и соглашения. Нет Feed/UserAlert/учебного каталога/команды/экзаменов/судейства. Старый push_enabled не превращён в фиктивный Flutter toggle: интеграция требует общей Firebase/APNs-конфигурации |

Источники аудита старого проекта: `app/Filament/Clusters/Commands/Resources/JudgeResource.php`, `Clusters/Commands/Pages/StudentTournamentActiveWithNumberFight.php`, `app/Filament/Pages/kata.php`, `app/Http/Controllers/KataController.php`, `resources/js/components/KataTables.vue`; для мастера `Clusters/Education/Resources/EducationKlassVideoResource.php` и Pages, `Policies/EducationKlassVideoPolicy.php`, `Pages/{Profile,Agreement,About,RatingPage,UserAlert,Feed}.php`, `Traits/CanEditTrait.php`. Отдельно проверялись route/policy/query/visibility и стандартный переход строки Filament, а не только видимые action-кнопки. Импортированная локальная матрица ролей была прочитана без изменения; это не утверждение о неизменности production permissions.

### Backend и переиспользование

- [MobileAppAccess](../app/Services/Account/MobileAppAccess.php) задаёт роль и server navigation. Staff-аккаунты допускаются только с единственной ролью Judge или Master; смешанные роли закрываются, а не получают права по порядку проверки. [MobileRoles](../app/Http/Middleware/MobileRoles.php) разделяет группы [routes/api.php](../routes/api.php): расширение общего login не открывает новые роли в Coach/Student endpoints. Сессия, secure token, reset, согласия и logout общие.
- [MobileJudgeController](../app/Http/Controllers/Mobile/MobileJudgeController.php): `GET judge/tournaments`, `GET judge/tables`, `GET judge/tables/{list}`, `GET/POST judge/tables/{list}/scores/{pool}`, `GET files/judge/{pool}/{student}`. Queue/picker по 20, строки этапа по 30. JudgeTables пакетно загружает составы, тренеров, заявки и категории; JSON содержит только собственную оценку. `currentScore` нужен для явного разрешения конфликта, не для безусловной перезаписи.
- [KataMutation](../app/Services/Tournaments/Kata/KataMutation.php) после блокировки турнира повторно читает и блокирует действующего пользователя; смена роли/организации/позиции закрывает старую колонку. Согласованные 0–10 с шагом 0,1, original_value, расчёт производных и аудит остаются в общих KataScoreService/KataScores. Stage guard проверяется на заблокированном актуальном наборе пулей. Panel downloadPdf теперь явно отказывает Judge; служебные выгрузки/артефакты остаются в своих организационных группах доступа.
- [MobileMasterController](../app/Http/Controllers/Mobile/MobileMasterController.php): `GET master/works`, `GET/POST master/works/{work}`, `GET files/master/{work}`. [MasterReviews](../app/Services/Education/MasterReviews.php) проверяет единственную роль, reviewer_id и оплату, блокирует actor/work, проверяет ревизию всех значимых полей и пишет old/new со student_id/reviewer_id в одной транзакции. Повтор идентичного состояния не создаёт второе событие. Подмена участника/назначения/оплаты/файла/категории запрещена.
- StudentEducationWorks/CoachEducationWorks продолжают скрывать черновик и показывать только опубликованный разбор. Возврат мастера в ожидание немедленно скрывает результат в следующих GET ученика/тренера; новая рассылка не выдумывается. [ReviewAssignment](../app/Services/Education/ReviewAssignment.php) переиспользуется созданием работы и EducationWorkPayments. Автоматическое восстановление назначения возможно только до нового платежа; оплаченные исторические работы массово не переназначаются.
- [MobileStaffProfileController](../app/Http/Controllers/Mobile/MobileStaffProfileController.php), [MasterProfile](../app/Services/Account/MasterProfile.php): `GET/POST staff/profile`. Judge POST закрыт. Поля Master: first_name/last_name/patronymic/email/gender/birthday/rang/weight/city_training; number_brand/number_iko/number_certificate/last_examination_date/last_examination_city/last_receiving. Пять документов passport/brand/insurance/iko_card/certificate и avatar: jpg/jpeg/png/webp до 10 MiB, max 8192 px. При замене/удалении сбрасывается подтверждение; для страховки также срок. Файлы private S3, компенсация нового при ошибке и очистка старого после успешного сохранения через общий [ProfileFiles](../app/Services/Account/ProfileFiles.php), который также использует UpdateStudentProfile.
- Удаление своего Master через общий MobileAccountController требует пароль, блокировку пользователя и отсутствие ожидающих оплаченных работ; soft-delete, отзыв всех токенов и аудит сохраняют авторство/работы. Judge собственного удаления не получает: аккаунтом управляет организация.
- [Миграция индексов](../database/migrations/2026_09_09_220000_index_staff_workflows.php): reviewer_id/payment/review/id для очереди, list_id/round/id для этапа ката. Применена отдельно в локальной рабочей Docker-БД без очистки данных и без перезапуска MySQL. При развёртывании применить эту миграцию обычным release-процессом.

### Flutter

- Общие ApiClient/SessionController/CoachShell/CoachBottomNav. Judge стартует в «Судейство», нижние пункты «Судейство / Профиль»; меню соглашений и выхода. Master стартует в «Разборы», нижние пункты «Разборы / Рейтинг / Профиль»; меню About/соглашений/выхода. Server navigation и role проверяются также на backend; отсутствие пункта не является защитой. После смены identity перестраивается оболочка и закрываются старые маршруты.
- [staff_queue_screen.dart](../../karaterating_trainer/lib/staff/staff_queue_screen.dart): поиск с debounce и защитой устаревших ответов, фильтры, повтор той же страницы после ошибки, подгрузка и обновление после возвращения из детали. Баллы опубликованной работы видны в очереди. [judge_table_screen.dart](../../karaterating_trainer/lib/staff/judge_table_screen.dart): два этапа, свои оценки, модалка ввода/очистки, конфликт с сохранением введённого и явным получением текущей оценки, read-only карточка участника и ProtectedVideoPlayer.
- [master_review_screen.dart](../../karaterating_trainer/lib/staff/master_review_screen.dart): полная категория, исходное видео, редактор четырёх полей, черновик/публикация/подтверждённый отзыв, unsaved guard и сохранение ввода при ошибке. После успеха подтверждение показывается внутри формы и не перекрывает следующие кнопки. [staff_profile_screen.dart](../../karaterating_trainer/lib/staff/staff_profile_screen.dart): компактный профиль, отдельный режим редактирования Master, файлы/просмотр/удаление, общая форма удаления аккаунта. Контроллеры живут до dispose экрана, не уничтожаются до завершения закрывающей анимации.
- Подписи RU/EN: [staff_strings.dart](../../karaterating_trainer/lib/l10n/staff_strings.dart), backend [lang/ru/staff.php](../lang/ru/staff.php)/[lang/en/staff.php](../lang/en/staff.php). Видео и свои документы открываются через существующий ProtectedMedia/авторизованные пути, без bearer в URL, без раскрытия постоянного S3-ключа.

### Проверки этапа

- [MobileStaffTest](../tests/Feature/MobileStaffTest.php): 11 HTTP-сценариев, все пять позиций, межролевые отказы, смешанные роли, своя колонка/конфликт/стадия, прямой полный PDF, group/round video Range, private profile, реальный login/consent/logout через тестовое HTTP-ядро; назначение/оплата/ревизия Master, идемпотентный replay, видимость публикации у Student/Coach, профиль/файлы/удаление, rollback при ошибке журнала и ограничение запросов пагинированных списков. MobileEducationTest дополнен отсутствующим мастером до платежа и потерей назначения к callback, gateway fake.
- Полный PHP-прогон 09.09.2026: **291 тест / 3847 assertions**, SQLite `:memory:` с пустым DB_URL и отдельным APP_CONFIG_CACHE. Это новый backend-прогон, а не перенесённый результат предыдущего этапа.
- [ConcurrentResultsTest](../tests/MySql/ConcurrentResultsTest.php): **1 тест / 36 assertions** на реальных отдельных соединениях MySQL в `kr_scope_test_20260909_staff`. Проверены параллельные судейские колонки, одна ячейка, смена позиции, конфликтующие версии разбора и существующие исходы боёв. Рабочая БД не очищалась. Аудит/изменения проверяются атомарно; реальных писем/платежей не было.
- [staff_workflow_test.dart](../../karaterating_trainer/test/staff_workflow_test.dart): навигационные capabilities, retry пагинации, conflict/rebase оценки, черновик/публикация/отзыв, round-trip профиля после ошибки, 360/393/430 px, RU/EN, light/dark, увеличенный текст 1,6. Тестовые снимки очереди/таблицы/редактора/профиля с читаемым шрифтом просмотрены; API подменён. Это не E2E с production S3 и не личная визуальная приёмка владельца.
- iOS debug-сборка для симулятора выполнена: `karaterating_trainer/build/ios/iphonesimulator/Runner.app`. Реальные аккаунты/роли и production-файлы ради проверки не менялись; внешняя оплата, push и проверка физического устройства остаются в scope.
- Финальный полный Flutter-прогон этого этапа: **80 тестов**, `flutter analyze --no-pub` без замечаний. PHP Pint применён к изменённым файлам. Локальный HTTP smoke нового `/api/mobile/judge/tables` без токена возвращает 401, а не страницу/404. Backend-код и индексы применены локально; Docker/MySQL перезапускать не нужно. Для новых Flutter-экранов запустить пересобранное приложение.

<a id="student-mobile"></a>
## Ученик в общем Flutter-приложении

Цель уточнена владельцем 09.09.2026: **одно приложение Coach/Student, отдельное нижнее меню и доступные действия по роли**, не Vue-кабинет и не WebView. 17.09.2026 ручная приёмка закрыта по сообщению владельца, отдельный scope удалён; общие внешние зависимости сохранены в [remaining-scope.md](remaining-scope.md). Это не новый автоматический прогон и не подтверждение интеграций. Предыдущий web-этап ниже сохранён как карта переиспользуемого backend, а не выполненная мобильная работа.

- [MobileAppAccess](../app/Services/Account/MobileAppAccess.php) возвращает `navigation.bottom/menu` при login/auth/user. Student получает Рейтинг/Лента/Профиль, турниры, доступное обучение, «О нас», соглашения и выход. Экзамены видны при разрешении живого тренера. Отдельный пункт «Заявки и оплата» убран у Student и Coach по решению владельца; оплата в предметном сценарии и возврат из провайдера сохранены. Coach сохраняет собственное меню. Редактор каталога, чужие ученики, приглашения, генерация, оценки/победы ученику недоступны.
- [MobileAppMember](../app/Http/Middleware/MobileAppMember.php) допускает внутренних Coach/Student к общим reads и доменным self-действиям. Coach-only picker/attach других учеников, приглашения, настройки, быстрые данные и Excel экзамена остаются закрыты Student. Проверять конкретный сервис, а не только middleware.
- MobileStudentController разрешает GET/update/history только владельцу-Student или тренеру своего ученика через StudentProfileAccess. Общий UpdateStudentProfile обслуживает ФИО/отчество/пол/город/email, аватар, пять документов и реквизиты. StudentEditScreen предоставляет self-поля и удаление аватара/файлов. Ранг/дата рождения зависят от `can_edit_students` владельцев активных турниров, в которых заявлен ученик, а не от глобального флага его домашней организации; вес блокируется участием. Подтверждение документов не разрешено. StudentProfileScreen(ownProfile: true) имеет общую нижнюю навигацию; история/медали используют StudentCompetitionHistory/StudentMedalService, без второй формулы в Dart.
- **Присоединение к новому тренеру, 17.09.2026:** после открепления прежним тренером ученик открывает в собственном Flutter-профиле «Присоединиться к тренеру», вводит существующий `CoachInvitations` код, видит только имя и клуб и подтверждает. [JoinCoach](../app/Services/Team/JoinCoach.php) и [MobileJoinCoachController](../app/Http/Controllers/Mobile/MobileJoinCoachController.php): POST `account/coach/preview` и `account/coach/join`, только авторизованный внутренний Student с согласиями/завершённой анкетой, throttle; код не передаётся в URL или аудит. `StudentProfileAccess.capabilities.join_coach` использует то же правило. При наличии прежнего `coach_id` смена запрещена, даже если соответствующий аккаунт удалён; предварительное открепление остаётся обязательным. POST заново проверяет код, ID показанного тренера и действующую принадлежность с блокировками coach → student. Меняются только `coach_id` и `organization_id` по новому тренеру; профиль, email/пароль, документы/подтверждения, спортивный период, заявки/результаты и токены сохраняются. Ожидающие приглашения этому email от выбранного тренера подтверждаются в той же транзакции, письмо не требуется. Повтор успешного POST к тому же тренеру идемпотентен; ошибка аудита откатывает привязку и приглашения. Журнал `student.coach.joined` содержит actor/target, old/new связей и IDs приглашений, без кода. Права старого/нового тренера следуют текущей связи.
- [StudentJoinCoachScreen](../../karaterating_trainer/lib/students/student_join_coach_screen.dart) сохраняет код/подтверждение при сетевой ошибке, сбрасывает просмотр тренера при изменении кода/конфликте, закрывается только после успешного ответа, обновляет API identity/menu без новой сессии. StudentProfileScreen обновляет профиль после возврата, при повторном открытии вкладки и resume. [StudentJoinCoachTest](../tests/Feature/StudentJoinCoachTest.php): HTTP-отказы, legacy/pivot role, открепление → preview → join, сохранность данных/файлов/участия/токена, повтор, конфликт, отменённый тренер и rollback журнала; почта fake. [student_join_coach_test.dart](../../karaterating_trainer/test/student_join_coach_test.dart): подтверждение, ошибки/повтор, capability и 360/393/430 px × RU/EN × обе темы × текст 1×/2×, снимки просмотрены. Итог 17.09.2026: **342 backend-теста / 4552 assertions** в SQLite `:memory:` с отдельным config cache, **102 Flutter-теста**, analyze без замечаний, Pint и Vue build прошли. Реальная конкурентная гонка MySQL/физическое устройство этим прогоном не проверялись; рабочие аккаунты не перепривязывались.
- iOS debug-сборка с JoinCoach собрана и установлена поверх приложения в открытом iPhone 17 Pro simulator 17.09.2026, без удаления данных. Для физического телефона нужна актуальная сборка; backend-миграции и перезапуск Docker этому изменению не требуются.
- С 17.09.2026 новый Student после подтверждения email автоматически входит через [MobileSession](../app/Services/Account/MobileSession.php), общий с обычным mobile login. Создание аккаунта, привязка, выдача токена и аудит атомарны; challenge одноразовый. Flutter сохраняет ответ через `SessionController.acceptSession` в прежнее защищённое хранилище, без повторного ввода пароля. Coach сохраняет прежний сценарий входа. При временной ошибке secure storage регистрационный экран повторяет сохранение полученной сессии, а не повторное потребление email-кода.
- Новому ученику сервер назначает `users.student_profile_setup_required=true` ([миграция](../database/migrations/2026_09_17_180000_add_student_profile_setup_required.php)); существующие аккаунты не переводятся в этот режим. После согласий открывается обязательная [StudentProfileSetupScreen](../../karaterating_trainer/lib/students/student_profile_setup_screen.dart), использующая `StudentEditScreen(setup: true)`. Обязательны имя, фамилия, дата рождения, пол, вес 1–300 кг, кю/дан и город тренировок; фото и рост необязательны. **Документов и отчества в регистрационной анкете нет**, согласно решению владельца. Существующий раздел документов профиля сохранён.
- [StudentProfileSetup](../app/Services/Students/StudentProfileSetup.php) валидирует итоговое состояние внутри заблокированного `UpdateStudentProfile`, который атомарно снимает флаг и пишет `student.profile_setup.completed`. Только первое заполнение пустых birthday/rang разрешено независимо от последующего запрета организации; существующие значения и запрет изменения веса участника не обходятся. Флаг не принимается из клиентского payload. Его возвращают login/registration/auth-user, поэтому выход, перезапуск или другой телефон не обходят анкету. `MobileAppMember` закрывает остальные mobile workflows кодом `409 profile_setup_required`; остаются собственный профиль/медиа, согласия, проверка сессии и выход/удаление по прежним правам. Согласия имеют приоритет; deep links ждут завершения обоих шагов.
- Проверки первичной анкеты 17.09.2026: полный backend **310 тестов / 4187 assertions** в SQLite `:memory:` с fake mail, полный Flutter **98 тестов**, после визуальных правок повторно **45 затронутых тестов**, `flutter analyze --no-pub` без замечаний. `MobileRegistrationTest` проверяет автоматическую сессию, gate, согласия, валидацию, повторный вход, одноразовость и rollback аккаунта/токена/журнала при сбое. `student_profile_setup_test.dart`, `registration_test.dart`, `session_test.dart`, `widget_test.dart` покрывают сохранение ввода при ошибке, secure storage retry, последовательность экранов, RU/EN, обе темы, 360/393/430 px и системный текст до 2×. Снимки формы с подменённым API просмотрены; реальное письмо/физическое устройство этим не проверены. Миграция применена отдельно к локальной Docker-БД без очистки; iOS debug-сборка установлена в текущий симулятор без удаления данных.
- ApiClient хранит проверенные accountRole/accountId/navigation только в памяти. SessionController проверяет ответ роли до входа и после restore/resume; изменение Coach/Student увеличивает generation и очищает маршруты, неподдерживаемая роль сбрасывает сессию. Bearer остаётся в прежнем защищённом AuthSession, названия secure-storage ключей не менялись.
- CoachShell/CoachNavigation/CoachBottomNav сохраняют прежние имена, но являются общей ролевой оболочкой. Student после первичной анкеты стартует с собственного профиля, Coach с ленты. Повторный переход сохраняет ленту. Платёжный deep link открывается после восстановления сессии/согласий/анкеты, API проверяет владельца платежа. Login предлагает нативную регистрацию ученика с кодом тренера либо тренера с кодом организации, без браузера и bearer в URL.
- MobileFeedController ограничивает Student scope значениями all/mine/organization, Flutter скрывает Мои ученики/Тренеры. Общие FeedAccess/Posts/Discussion, NotificationCounter/Screen, AgreementsScreen/consent overlay, рейтинг и About обслуживают обе роли. Оферта принимается отдельно перед оплатой через PaymentOffer и payment_offer_dialog, с версией существующего Agreement.

### Сервисы Student-сценариев

| Сценарий | Переиспользуемая реализация / правило |
| --- | --- |
| Спортивный период | `Students/CompetitiveRecordPeriod` вызывается UpdateStudentProfile: соревновательный ранг, восьмилетие, сохранение состоявшейся истории. Клиент не задаёт competitive_record_starts_at |
| Удаление и восстановление | MobileAccountController: пароль, StudentProfileAccess, soft-delete, отзыв токенов. `Account/StudentAccountRestore` + MobileStudentRestoreController + `auth/restore_account_dialog.dart`: код на email на 15 минут, пять попыток, лимиты запросов, новый пароль, одноразовость и аудит. Только самостоятельное удаление; административное не обходится |
| Чемпионаты/турниры | `MobileTournamentAccess` разделяет Coach/Student. Student видит только турниры живого прикреплённого тренера; счётчики и фильтры чемпионата считают этот же набор. Общие detail/list/bracket/public summary не выдают чужие документы, email или видео |
| Самостоятельное участие | `StudentTournamentEnrollment` + mobile `/self` POST/DELETE и TournamentDetailScreen. Личный автоподбор общий. `TournamentApplications::detach(..., selfOnly: true)` снимает только конкретную membership ученика, сохраняя остальных в группе. Допуск, флаг тренера, сроки/генерация, транзакции и аудит проверяются сервером |
| Экзамен | `Examinations/StudentExaminationEnrollment` + MobileExaminationController + ExaminationDetailScreen. Просмотр своей организации с фильтром тренера, только self-запись/отмена, без Coach picker/Excel. Отзыв флага и удаление тренера закрывают действие |
| Турнирная оплата | Общие OnlineKataPaymentService/KataPaymentReconciler/OnlineKataApplication. Student-плательщик платит только за себя, принимает оферту; обычный POST не обходит оплату. Повторы используют устойчивый ключ. Незавершённая оплата другим плательщиком блокирует второй платёж Coach/Student. Поздние права/сумма/валюта/shop проверяются до прикрепления, конфликт не выдаётся за успех |
| Видео турнира | Общие KataVideoAccess/KataFinalVideoService, protected Range, KataVideoUploadSheet/ProtectedVideoPlayer. Свои видео нужного круга, категория, финальная замена; оба круга 100 MiB, прогресс/отмена/компенсация. Чужие приватные видео закрыты |
| Мобильные выгрузки | `Exports/MobileTournamentExports`, PanelTasks(kind=mobile_tournament)/RunPanelTask, mobile tasks routes. Списки Excel/PDF, пакет таблиц, пули только при `download_puli_tournament`. Меню без организаторских отчётов; собственное задание, повторная авторизация при выполнении/скачивании, приватный результат, native save/share. PDF/XLSX не рендерятся в мобильном HTTP-запросе |
| Мои разборы | `Education/StudentEducationWorks` + MobileStudentEducationController + education_work_editor/EducationVideoScreen. Все собственные, включая неоплаченные; категория/видео, до проверки. Цена/reviewer/владелец назначаются сервером, review скрыт до проверки. Незавершённый платёж блокирует изменения, оплаченная категория не меняется; legacy transaction запрещает удаление предмета учёта |
| Оплата разбора | `Education/EducationWorkPayments`, EducationPayment: отдельная долговечная заявка, price_minor snapshot, идемпотентность, авторитетный YooKassa fetch, проверки metadata/shop/суммы/владельца/работы. Оферта, отмена/retry, конфликт, аудит. Flutter resume/polling, `karaterating://education/{work}`; OnlineKataReturnPage умеет оба типа возврата. Реальные платежи не выполнялись |

Применены миграции `2026_09_09_180000_create_education_payments` и `2026_09_09_181000_create_account_restore_challenges`; существующие данные не пересоздавались. `queue:restart` обновляет долгоживущий worker, перезапуск MySQL не нужен.

Сборки этого этапа: `npm run build`, `flutter analyze --no-pub` без замечаний, `flutter build ios --simulator --debug --no-pub` успешны. Контрольные widget-снимки учебного workflow находятся в `karaterating_trainer/build/auth-screenshots/education-*.png`; плеер в этих снимках подменён тестовой платформой, не выдавать его за проверку настоящего S3-видео. Непроверенная работа отдельно показывает неоплаченный статус, оплаченная ожидает проверки.

Проверки расширения 09.09.2026: **280 backend-тестов / 3673 assertions**, SQLite `:memory:`, fake media/mail/gateway; **74 Flutter-теста**. StudentAccountTest проверяет self-поля/файлы/период/удаление/restore; MobileTournamentEnrollmentTest включает отмену собственной группы, назначенный каталог и реальное формирование XLSX-задачи с проверкой чужого/отозванного доступа; OnlineKataPaymentsTest включает Student и двух плательщиков; MobileEducationTest включает собственные CRUD/приватный Range/оплату; MobileFeedTest и MobileNotificationsTest прогнаны также под Student. Widget-проверки включают self-профиль, запись/отмену экзамена, редактирование работы без потери видео, роль/навигацию, RU/EN и обе темы 360/393/430 px. Это не проверка доставки реальных писем, sandbox callback или физического устройства. Внешние зависимости и визуальная приёмка остаются в Student scope.

<a id="student-web"></a>
## Предыдущий Web-этап ученика

Добавлен 09.09.2026 по ошибочному предположению о платформе. Владелец уточнил: нужен Student в существующем Flutter-приложении, не web. Ниже историческая карта сохранённого кода для переиспользования backend; Vue-код без отдельного указания не удалён, его дальнейшее развитие не является целью. Перечисленные тесты не доказывают готовность мобильных сценариев.

| Сценарий | Код и ограничения |
| --- | --- |
| Вход и меню | App.vue/PanelLayout: Student стартует с `/panel/profile`, а не dashboard организации. Профиль, турниры, экзамены, рейтинг, обучение, уведомления, соглашения, «О нас»; никаких команд/шаблонов/настроек организации |
| Собственный профиль | [StudentAccountPage](../resources/js/pages/panel/StudentAccountPage.vue), StudentController::selfProfile/updateSelf и StudentDetailPage. Общие расчёты медалей/рейтинга и пагинированной истории, не второй Student-расчёт |
| Редактирование и файлы | StudentProfileAccess/UpdateStudentProfile: self или свой ученик Coach, отдельные полевые capabilities; birthday/rang/delete self зависят от can_edit_students владельцев активных турниров участника (TournamentProfileAccess), вес self блокируется активным участием. ФИО/отчество/email/город/реквизиты, аватар и пять документов, приватный просмотр, замена/удаление с компенсацией, сброс подтверждения и self-аудит. StudentOwnDocuments заменяет организаторские кнопки подтверждения собственным readonly-списком статусов |
| Удаление | [StudentAccountController](../app/Http/Controllers/Panel/Account/StudentAccountController.php): пароль, серверная capability, soft-delete, отзыв сессий/токенов/push и выход. Безопасный restore ещё не реализован |
| Согласия/уведомления | AccountAccess::authorizeReader допускает Student, но authorize профиля организации не расширен. Общие Agreement/NotificationController и Vue-страницы; gate актуальных соглашений. [agreementReturn](../resources/js/composables/agreementReturn.js) сохраняет только внутренний `/panel`-маршрут без внешнего redirect и возвращает после принятия; роли/права по-прежнему проверяются назначением |
| Экзамены | ExaminationController и StudentExamAction: виден публичный состав экзамена своей организации при разрешении тренера, записаться/открепить можно только себя. Повторная проверка в транзакции, подтверждение и обработка ошибки, dynamic reload; Excel Student запрещён |
| Обычная заявка | [StudentTournamentEnrollment](../app/Services/Tournaments/StudentTournamentEnrollment.php), [StudentEnrollmentController](../app/Http/Controllers/Panel/Tournaments/StudentEnrollmentController.php), [StudentTournamentActions](../resources/js/components/panel/StudentTournamentActions.vue). ID из сессии, живой допущенный Coach, его флаг self-записи, стадия/готовая сетка, канонический ранг и assignment, транзакция/идемпотентность/аудит. Личная membership отдельна от групповой; групповой DELETE Student пока запрещён, потому что общий detach удаляет весь состав |
| Просмотр турнира | PanelTournamentVisibility отбирает турниры тренера; удалённый/не-Coach не даёт доступа. Bracket/Kata view проверяют Student-допуск. Студент не получает email тренеров, чужие URL видео и даты/причины документов, организаторские downloads; основные raw roster joins исключают deleted users. Все вложенные ссылки и групповые сценарии ещё требуют отдельной проверки |
| Online-видео | KataVideoAccess/KataFinalVideoService и авторизованная выдача TournamentStudentController: Student только своё видео при допуске. Собственный финал загружается/заменяется с общей capability, 100 МБ, protected storage/Range/компенсацией. Обычный self POST запрещает обход оплаты; создание Student-платежа ещё не подключено |
| Учебные каталоги | [StudentEducationController](../app/Http/Controllers/Panel/StudentEducationController.php), EducationAccess/EducationCatalog, [StudentEducationPage](../resources/js/pages/panel/StudentEducationPage.vue): четыре раздела с resource permissions, категории/поиск/пагинация, названия/обложки/плеер, приватный Range. Own учебные работы и оплата пока не реализованы |

Новые UI-подписи: [student.js](../resources/js/i18n/student.js), backend [ru/student_account.php](../lang/ru/student_account.php)/[en/student_account.php](../lang/en/student_account.php); общие тексты берутся из существующего i18n. Клуб только Coach. Student не получает права подтверждать документы/вес, менять ранг при запрете или редактировать результаты соревнования.

Проверки 09.09.2026:
- [StudentAccountTest](../tests/Feature/StudentAccountTest.php): 10 сценариев self-профиля, ограничений, файлов/rollback, gate/индивидуальных уведомлений, удаления, экзаменов, каталогов/permissions/Range, личной+групповой заявки, закрытой стадии, online-обхода и своего финального видео, удалённого тренера/чужих приватных полей.
- Полный backend suite: **257 тестов / 3326 assertions**, SQLite `:memory:`, `php -d memory_limit=512M vendor/bin/phpunit`. При стандартных 128 МБ прежний тест XLSX на 5000+ учеников исчерпывает память; это не провал assertion, не причина менять рабочую БД или memory_limit HTTP.
- Vite production build и 11 браузерных workflow прошли: новый `student-account` и прежние `account`, `student-history`, `panel-mobile`, `kata`, `fight`, `tournament-list`, `tournament-management`, `team`, `external-form`, `panel-export`. Student-тест использует RU/light и EN/dark, 360/393/430/1440 px, round-trip отчества, сохранение формы при ошибке, readonly-документы, каталог/плеер, личную запись/отмену турнира. Дополнительный повтор Student-теста проверил запись в экзамен и отмену с ошибкой 503/повтором: модалка остаётся открытой при ошибке, чужая строка не имеет действия. API подменён, реальные письма/платежи/медиа не использовались.
- Student-роль не объявляется полностью перенесённой: лента, собственные учебные работы/оплата, Student online-плательщик, часть экспорта, restore, спортивный период и дополнительные проверки остались в scope. Это незавершённая разработка, не только визуальная приёмка владельцем.

<a id="entities"></a>
## Ключевые сущности и связи

- [User](../app/Models/User.php): организация и тренер тоже пользователи. `organization_id` и `coach_id` не взаимозаменяемы. Для проверки роли использовать `hasProjectRole`, `hasAnyProjectRole` и `scopeRole`: учитываются legacy `role_id` и связи `model_has_roles`, а не только один из источников.
- [Championship](../app/Models/Championship.php) содержит турниры, [Tournament](../app/Models/Tournament.php) задаёт дисциплину, систему ката, владельца и сроки. Явный допуск тренеров хранится в `tournament_treners`; [OrganizationTournament](../app/Models/OrganizationTournament.php) описывает заявку другой организации, не передачу владения.
- [TemplateStudentList](../app/Models/TemplateStudentList.php) является общим шаблоном организации; [ListTournament](../app/Models/ListTournament.php) прикрепляет его к турниру. Шаблон может использоваться несколькими турнирами.
- [StudentTournament](../app/Models/StudentTournament.php) хранит участие и совместимый основной указатель списка. [TournamentStudentList](../app/Models/TournamentStudentList.php) хранит конкретную membership и group_id. Перенос/открепление адресовать конкретной заявке, не удалять все связи student/tournament.
- [Pool](../app/Models/Pool.php) хранит обычные, финальные, третьи и Round Robin бои; [KataPool](../app/Models/KataPool.php) хранит личные/групповые строки этапов, оценки и призовые флаги. Тип/стадия обязательны для интерпретации результата.
- [ExternalForm](../app/Models/ExternalForm.php) и `external_form_students/groups/applications/import_runs` разделяют публичную строку, доверенную идентичность, стабильную группу, заявку и запуск импорта. Часть этих таблиц обслуживается Query Builder внутри доменных сервисов; отсутствие отдельной модели не повод создавать второй механизм.
- [OnlineKataApplication](../app/Models/OnlineKataApplication.php) является долговечным источником состояния оплаты, не cache или факт наличия StudentTournament. [PanelTask](../app/Models/PanelTask.php) хранит очередь/готовность файлов и массовой генерации; это другой workflow.
- [WaitConfirmationInvitation](../app/Models/WaitConfirmationInvitation.php): target_role различает приглашения тренера и ученика. `organization_join_codes` и `coach_join_codes` постоянные, OTP подтверждения email краткоживущий; это разные секреты/сущности.
- [UserAlert](../app/Models/UserAlert.php) и индивидуальный `user_alert_user.read_at` не заменяются общим флагом сообщения. `agreement_acceptances` хранит согласие с конкретной версией, legacy-флаги пользователя недостаточны для доказательства согласия.

<a id="invariants"></a>
## Ограничения, которые нельзя случайно отменить

- Клуб ученика всегда от тренера. Для разрешённого организационного просмотра архивного тренера сохраняются прежний клуб и организация; это не разрешение входа архивному аккаунту.
- Секретарю не открывать управление судьями/секретарями и экзаменами. Принятая заявка организации на чужой турнир не делает её владельцем.
- Каталог «Все» не открывает чужие документы и составы. Турнирная карточка соперника отличается от закрытого профиля ученика.
- Одна заявка не равна паре student/tournament: существуют отдельные личные/групповые membership, стабильный group_id и связи импорта.
- Подбор возраста на календарный день комиссии (`TournamentAge`), не на день подачи заявки; нисходящий диапазон кю и восходящая пороговая ветка имеют разный смысл. Не «исправлять» сравнением rang_to >= rang_from.
- Готовая сетка/таблица блокирует изменение состава без явного согласованного сценария; результат, зависимости, ревизия и журнал изменяются атомарно.
- Ката: 0–10, шаг 0,1; все пять оценок, исключение ровно одного минимума/максимума. Изменение предварительного этапа не оставляет старые финал/медали.
- Собственные документы/реквизиты тренера в мобильном профиле отменены пользователем; редактирование общего шаблона из турнира тоже отменено. Не включать эти отменённые пункты обратно в долг.
- Внизу Flutter только «Рейтинг / Лента / Профиль» и компактное меню, единый Scaffold.bottomNavigationBar. Не возвращать фильтры внутрь чемпионата, кнопки «Профиль» на участниках, скачивания в шапку пули, учеников под профилем тренера, категории/«важное» уведомлений.
- Нет публичного storage symlink для приватных файлов. Новый файл компенсируется при ошибке, прежний удаляется после успешного сохранения и проверки других ссылок.
- Онлайн-ката: первый и финальный круг имеют одинаковый предел 100 МБ. В коде это 100 MiB = 104857600 байт = 102400 КБ Laravel. Не возвращать прежние 50 MiB/97656 КБ. Лимит медиа ленты этим решением не меняется.
- Нет реальных писем, оплат, массового импорта/пересчёта или изменения общей MySQL ради тестов. Внешняя приёмка требует разрешённых тестовых аккаунтов, sandbox и отдельного окружения.

<a id="runtime"></a>
## Окружение, очереди и проверка изменений

- Web v2 локально: `http://127.0.0.1:8080`; старый референс использовался на `:8099`. Перед запуском проверить реальные процессы/порты, не считать эти адреса обещанием работающего сервера.
- Старый референс также запускается через PHP 8.4: `php artisan serve --host=127.0.0.1 --port=8000` из `karaterating`. Для ошибки входа `POST panel/login` сначала проверить Livewire: на 14.09.2026 браузер получал HTML вместо JS (`Unexpected token '<'`), хотя свежий запрос сервера был корректным. Локальное исправление: AppServiceProvider при APP_ENV=local использует `/_legacy/livewire/livewire.js` с `Cache-Control: no-store, private`; serviceworker.js на localhost/127.0.0.1/[::1] не кэширует страницы, очищает только свои pwa-кэши, принимает контроль и отправляет same-origin GET в сеть без HTTP-кэша, без HTML fallback. Production-ветка PWA и авторизация не изменены, POST-route логина добавлять не нужно. Проверка без рабочей БД: `node --test tests/Unit/local-serviceworker.test.cjs` (6 сценариев); дополнительно проверены HTTP JS 200/application-javascript и браузерная форма на несуществующем аккаунте. Ошибки 403 старых иконок `/storage/...` не исправлять открытием приватного хранилища: это отдельная от входа проблема ресурсов.
- Docker и версии: [docker-compose.yml](../docker-compose.yml), [Dockerfile](../docker/php/Dockerfile), [composer.json](../composer.json), [package.json](../package.json); Flutter-плагины: [pubspec.yaml](../../karaterating_trainer/pubspec.yaml) и lockfile.
- Настройка origin Flutter: API_BASE_URL, в выпуске HTTPS. Loopback подходит iOS-симулятору, но не является адресом компьютера для физического телефона. Bearer не передавать браузеру.
- Планировщик: [routes/console.php](../routes/console.php). Каждые 5 минут kata:maintain-applications и ограниченный worker protected_media_cleanup; ежечасно panel:clean-tasks. Новые тяжёлые выгрузки обслуживает отдельный panel-worker.
- panel_tasks: PDF/XLSX и массовая генерация, долговечный статус, 600 секунд/512M, без обслуживания платежных/default-заданий. Очистка медиа: protected_media_cleanup/default, повтор при неудачном удалении. Импорт анкет и часть писем пока используют deferred после HTTP-ответа: это не тот же долговечный выделенный worker.
- Миграции применять после резервной копии и проверки окружения, не выполнять заново исторические команды из разделов на общей базе. Изменения конфигурации/кода worker требуют его перезапуска; правки Markdown не требуют Docker/rebuild.
- Для backend использовать изолированные APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:, пустой DB_URL и отдельный/отсутствующий config cache; проверять их перед RefreshDatabase. Рабочую MySQL не использовать как тестовую.
- Web: `npm run build`, `tests/Feature/`, `tests/ui/*-workflow.cjs`. Браузерные сценарии подменяют API, поэтому не доказывают сквозной backend-доступ. PDF: `PanelExportTest` и `tests/ui/verify-export-pdfs.py`.
- Flutter: `flutter test`, `flutter analyze --no-pub`, `integration_test/` и `test_driver/`. Симулятор и тестовый MP4 не заменяют физическое устройство, реальные исходники и доставку push/оплаты/почты.
- Медиа восстановлены с исходного сервера в приватный S3 09.09.2026; прежние числа отсутствующих локальных файлов больше не описывают рабочее хранилище. Итоговая сверка и четыре архивных исключения описаны ниже. Секреты Firebase/APNs/ЮKassa/Resend/S3 не записывать в этот документ.

<a id="production"></a>
### Сервер karate-rating.ru

Развёрнут 18.09.2026: **https://karate-rating.ru**, каталог `/var/www/karate_ratin_usr/data/www/karate-rating.ru/karaterating`. Владелец отдельно разрешил установку Docker и изменение nginx только этого домена. Другие сайты, host MySQL и их базы не изменялись; старый сайт `karaterating.ru` не переключался.

- [compose.production.yaml](../compose.production.yaml), проект Compose `karate_rating_v2`: `app`, `nginx`, `mysql`, `panel-worker`, `scheduler`. PHP 8.5 собирается через [Dockerfile.production](../docker/php/Dockerfile.production), nginx использует [production.conf](../docker/nginx/production.conf). Рабочие файлы bind-mounted; production vendor установлен из lock без dev, `public/build` собран локально. Зависимости и сборка не хранятся в Git.
- Снаружи доступен только HTTPS host nginx; Docker nginx слушает **127.0.0.1:18080**. MySQL не публикует порт; FPM также не опубликован. Compose принудительно задаёт `DB_HOST=mysql`, `DB_DATABASE=karaterating`, `DB_USERNAME=karate_v2`, пустые `DB_URL`/`DB_SOCKET`. Пароли отдельные, только в приватной `.env`; root-пароль БД не применяется приложением. Не подключать v2 к host `127.0.0.1:3306`.
- Данные MySQL: абсолютный `MYSQL_DATA_DIR` в `.env`, сейчас `<project>/.deployment/mysql`. Нельзя переносить/удалять этот каталог при обновлении, выполнять `down -v`, `migrate:fresh` или запускать обычный `docker-compose.yml`. Долгоживущие PHP-процессы читают `.env` группы UID/GID 82; `storage` и `bootstrap/cache` также принадлежат 82. Artisan запускается с `--user 82:82`. После каждого `config:cache` выставлять `chmod 600 bootstrap/cache/config.php`: кэш тоже содержит секреты и не должен читаться другими пользователями общего сервера.
- Исходный дамп `../localhost.sql` импортирован **только в новый контейнер**, пользователем с правами только на новую схему; исходный файл не менялся, SHA256 проверен. На момент импорта: 3989 пользователей, 63 турнира. Все 22 новые миграции применены, pending нет. Четыре импортированных задания Filament перенесены внутри новой базы в `deployment_legacy_jobs_20260918`; они не исполняются v2. Пароли/роли существующих пользователей не менялись, рабочие аккаунты для проверки не создавались.
- `.deployment/backups/legacy-before-v2.tar.gz` содержит старое дерево проекта; `.deployment/legacy` сохраняет его распакованным, включая прежние `.env` и `.git`. Оба nginx-конфига сохранены как `nginx-active-before-v2.conf` и `nginx-available-before-v2.conf`. Этот каталог приватный, не коммитится и не обслуживается nginx. Не удалять его при deploy; резервные копии на том же диске не заменяют внешние бэкапы.
- Единственные изменённые настройки сайтов: `/etc/nginx/fastpanel2-sites/karate_ratin_usr/karate-rating.ru.conf` и одноимённая копия в `fastpanel2-available`. Сохранены сертификат, логи и Fastpanel includes, upstream заменён на `127.0.0.1:18080`. Выполнены `nginx -t` и graceful reload, без рестарта других приложений. Не перегенерировать этот vhost в Fastpanel без сохранения reverse proxy.
- Существующий приватный S3 подключён. Реальный одноразовый probe с сервера подтвердил запись, чтение, Range 206 и анонимный 403; probe удалён. После отдельного разрешения владельца 18.09.2026 докопированы все 150 найденных недостающих оригиналов нового дампа. Остались только 4 ранее известных архивных файла, отсутствующих и в источнике; подробности в разделе S3 ниже. Существующие объекты не перезаписывались, исходники не удалялись.
- **Общий S3-префикс не означает общую БД.** Массовый поиск осиротевших онлайн-видео теперь opt-in: `MEDIA_SWEEP_ORPHANED_KATA_UPLOADS=false` по умолчанию. Включать только когда единственная БД владеет всем префиксом. Сверка платежей и очистка конкретных отменённых заявок сохранены. Независимому тестовому окружению нужен отдельный S3-префикс перед изменениями общих файлов.
- Проверка опубликованного сайта без API mocks: Chrome 1440/393 px, изображения и отсутствие horizontal overflow, `/up` и app-links 200, защищённые API 401, закрытый `/storage` 404, `.env` 403. Вход на несуществующий адрес дал 422, а не CSRF/500; cookie Secure/HttpOnly. Просмотрены снимки лендинга и формы входа. Успешный вход реальным пользователем, платежи и письма этим не проверены. Регрессионные тесты защиты очистки: `OnlineKataPaymentsTest`, 20 тестов / 131 assertion, SQLite и fake S3/gateway; Pint успешен.

**Обновление:** сначала проверить Git, место на диске, `docker compose -f compose.production.yaml ps` и резервную копию именно контейнерной БД. Обновить только этот checkout, установить production Composer зависимости, доставить новую Vite-сборку. После проверки подключения выполнить нужные `migrate --force`, `config:cache`, `route:cache`, `view:cache` от UID 82. Пересоздавать только нужные `app/nginx/panel-worker/scheduler` с `--no-deps`, не MySQL. Изменение Dockerfile требует сборки `app`; изменения кода/config долгоживущего worker требуют его перезапуска. Проверить HTTPS, `/up`, очереди и отсутствие ошибок. Не переносить локальную `.env` целиком и не импортировать исходный дамп повторно поверх рабочей базы.

**Откат:** остановить только новые app/nginx/worker/scheduler, сохранить новую рабочую версию, вернуть прежнее дерево из `.deployment/legacy` и обе сохранённые конфигурации этого домена, затем `nginx -t`/reload. Новую контейнерную БД и S3 сохранить; откат кода не переносит новые пользовательские изменения обратно в старую базу. Host MySQL не восстанавливать и не изменять. На момент запуска свободно около 2.8 GiB; до активной эксплуатации увеличить диск и организовать внешние резервные копии.

<a id="storage-s3"></a>
## Приватное S3: хранение и перенос

Решение владельца: S3 используется новой web v2 и мобильным приложением через общий backend. Старый работающий сайт остаётся только источником копирования; его код, конфигурация и оригиналы не меняются. Бакет не открывать для анонимного доступа. Параметры и секреты находятся в `.env`, образец без ключей в `.env.example`.

- `MEDIA_STORAGE_DRIVER=s3` переключает **оба** логических диска: `public` → `karaterating/public`, `protected` → `karaterating/protected`. Префикс задаётся `AWS_ROOT_PREFIX`. Оба S3-диска пишут с private ACL; `public` обозначает доступные через приложение аватары/обложки/медиа ленты, а не публичность объектов Timeweb. `FILESYSTEM_DISK=protected` задаёт безопасный default для новых файлов.
- Endpoint/region/path-style задаются `AWS_ENDPOINT`, `AWS_DEFAULT_REGION`, `AWS_USE_PATH_STYLE_ENDPOINT`. Используется официальный `league/flysystem-aws-s3-v3`; не прописывать ключи в PHP, Flutter/Vue, URL или документации. Источник параметров совместимости: [Timeweb S3](https://timeweb.cloud/docs/s3-storage/tools/aws-cli), [Laravel Filesystem](https://laravel.com/framework/docs/13.x/filesystem).
- Относительные пути в БД сохраняются. `/storage/{path}` обслуживает PublicMediaController с прежней проверкой приватности, авторизованные файловые маршруты используют ProtectedMedia. Laravel потоково читает S3, не передаёт bearer внешнему домену и не скачивает целиком видео ради перемотки. `MediaStorage` поддерживает GET/HEAD, одиночный Range/206, безопасные заголовки и attachment. S3-ошибки возвращаются без подписанного запроса/ключей. Короткие URL S3 не сохранять как постоянные поля БД.
- Старый `storage/app/public` содержал закрытые документы/видео. Копия сохраняет пути в S3-префиксе `public`, но **объекты приватные**: ProtectedMedia проверяет каталоги и ссылки БД, авторизованное чтение ищет `protected`, затем legacy `public`. При замене старого документа `migrateFile` копирует в `protected`, сверяет SHA-256 и только затем убирает дубликат S3 `public`; исходники старого сервера это не затрагивает. Каталоги `passport/passports`, `brand/brands`, `insurance`, `iko_card`, `certificate`, `online-kata-videos`, `video/videos` не выдаются публичным маршрутом.
- Все существующие загрузки профиля/учеников/турниров/ленты/ката и файлы фоновых выгрузок используют эти диски. Очистка заменённых видео, документов и panel-task файлов работает с S3. Логотипы PDF читаются как ограниченные по размеру data URI через MediaStorage, не через локальный `path()`. Локальными остаются framework cache/session/temp, промежуточный рендер PDF/XLSX и статические assets приложения; это не резервное хранилище загруженных медиа.

### Инструменты и безопасное переключение

- [scripts/storage-transfer.py](../scripts/storage-transfer.py): временный rclone с проверкой SHA-256 релиза; ключи вводятся скрыто, runtime-каталог 0700/config 0600. `copy` использует `--immutable --checksum`, не `sync/move/delete`: нет удаления исходников или перезаписи отличающихся объектов. `check` использует `--download --one-way`, сравнивает содержимое; `size` даёт итоговый объём. HTTP/2 отключён в повторных проходах для обхода GOAWAY Timeweb. Не переносить `.gitignore`, `.DS_Store` и `livewire-tmp`.
- `php artisan media:copy-to-s3 public|protected [--source=...]`: локальный аудит по умолчанию, `--apply` копирует с SHA-256-проверкой и пишет сводку в activity_log. При конфликте сохранить обе стороны и выяснить причину, не добавлять массовый overwrite. Секреты в консоль не выводятся.
- `php artisan media:s3-check`: временный probe в `_checks`, реальная запись/чтение, потоковый Range и анонимный 403/404; probe удаляется. Использовать для проверки подключения, не заменяя им проверку прав пользователя на конкретный документ.
- `php artisan media:s3-audit [--missing]`: только чтение, сверка ссылок БД с одним обходом объектов S3; отдельно считает доступные/отсутствующие/внешние пути, не делает тысячи HEAD-запросов и не меняет БД. Неполная исходная копия не означает ошибку S3; отсутствующие оригиналы фиксировать отдельно.
- Сначала копирование/проверка и локальные новые файлы, затем переключение `.env`, сброс config cache и перезапуск PHP/долгоживущих workers. Проверить HTTP, реальный Range и авторизованные маршруты. MySQL и volumes не перезапускать/не удалять. При откате менять конфигурацию и сохранить новые S3-файлы; не считать старую локальную копию автоматически полной после новых загрузок.
- PHPUnit принудительно использует local-драйвер; общий tests/TestCase подменяет public/protected/s3 через Storage::fake, включая фоновые экспорты. S3MediaTest подменяет транспорт официального SDK. Обычные тесты не должны обращаться к бакету или удалять пользовательские объекты. Тесты покрывают private ACL/префикс, 206/HEAD/attachment, отказ чужому профилю и публичной ссылке, PDF, удаление старого S3-видео и проверку перед удалением legacy-дубликата.

### Завершённый перенос, 09.09.2026

- Скопированы **13915 исходных файлов / 31465643406 байт**. `verify-progress` скачал и сравнил содержимое каждого объекта; затем повторная сверка источника подтвердила 13915 совпадений, 0 расхождений. Исходники не удалялись, старый сайт не переключён. Его последующие новые загрузки не синхронизируются автоматически с v2.
- Дополнительно перенесены два локальных изображения v2 (404740 байт) и 10 уже имевшихся пустых файлов тестовых выгрузок. Итог: 13927 объектов, без временных probe. Эти пустые служебные XLSX не являются восстановленными пользовательскими документами; исходные копии сохранены. Перед переключением повторный проход локальных дисков не обнаружил новых файлов/конфликтов.
- `.env` переключён на `MEDIA_STORAGE_DRIVER=s3`, `FILESYSTEM_DISK=protected`; config cache сброшен, app/panel-worker/scheduler перезапущены. V2 снова открыта после короткого maintenance. MySQL, nginx и старое приложение не перезапускались. Flutter продолжает использовать те же API-маршруты; отдельные S3-ключи или новая клиентская сборка для этого переключения не нужны.
- Реальный probe от www-data: запись/обратное чтение, Range/206, анонимный 403; временный объект удалён. Сквозной HTTP через nginx/v2: 206 с корректными Content-Range/Content-Length; `/storage` для реального документа даёт 404, файловый API без сессии 401, прямой S3 URL перенесённого документа 403. Проверка своих/чужих пользователей выполнена HTTP-тестами с SDK mock; визуальная приёмка реального плеера на физическом телефоне остаётся в scope.
- Итог `media:s3-audit`: users 10406/10410 уникальных ссылок, student_tournaments 264/264, education_klass_videos 11/11, education_kata_videos 6/6, kata_competitions_videos 20/20 (включая обложки), championships 14/14, tournaments 88/88, posts 34/34. Счётчики разных таблиц не складывать как число уникальных объектов.
- **Четыре исторически отсутствующих файла**: `passport/01K9D7QXJCFBMYNR6XEHDZ4Z5A.webp`, `brand/01K9D7QXV43Q759KFKXSSSW8E8.webp`, `certificate/01K9D7QY3YHMCTSXJTHMAR0NDP.webp`, `avatar/01K9D7QXJ91S9457CEHJ6WGZYE.jpeg`. Все относятся только к users.id=3208, deleted_at=2025-11-06 21:48:03; в storage/public старого сервера оригиналы также отсутствуют. Не восстанавливать удалённую учётную запись и не очищать её поля автоматически. Поэтому полный архивный аудит ожидаемо возвращает exit 1, хотя недостающих файлов действующих записей не найдено.
- В activity_log записаны локальные проходы `media.copied_to_s3` и итог `media.s3_migration_completed` с источником, объёмом, сверкой и сменой драйвера. Временные ключи/rclone/manifest удалены со старого сервера, SSH закрыт. Рабочие ключи остаются только в конфигурации v2; после ротации обновить `.env` и перезапустить долгоживущие процессы.
- Проверки: **247 тестов / 3213 assertions**; из них S3MediaTest **9/40**, включая отсутствие двойного подсчёта путей аудита. PHP-форматирование успешно. Старые результаты Flutter/Vue относятся к предыдущему этапу, повторная клиентская сборка в S3-этапе не выполнялась.

### Докопирование нового дампа, 18.09.2026

- По запросу владельца источник: `/var/www/karaterating_usr/data/www/karaterating.ru/karaterating/storage/app/public`, не каталог нового домена. Старый проект подключался к одноразовому PHP-контейнеру **read-only**; подключение к старым базам не использовалось. План строился только по ссылкам новой Docker MySQL и полному инвентарю S3.
- Из 154 отсутствующих путей найдены и скопированы **150 файлов / 202103356 байт** (около 193 MiB): 102 в логический `protected`, 48 в логический `public`. Оба префикса имеют private ACL. На записи использованы повторная проверка существования, `If-None-Match: *` и Content-MD5; существующие объекты не перезаписывались. SHA-256 сверялся с оригиналом при копировании и повторным чтением всех 150 объектов: 150 совпадений, 0 ошибок. Исходные файлы не изменены.
- Проверен ACL каждого нового объекта: только владелец, без публичных grants. Реальные HTTP-проверки на новых объектах: прямой S3 URL даёт 403 для обоих дисков; `/storage/...` защищённого документа даёт 404, публичного изображения — 200. Авторизованный доступ сохраняется через существующие обработчики, бакет не открывался.
- Итоговый `media:s3-audit`: **14077 объектов**, 4 отсутствующих пути только у удалённого users.id=3208 (`deleted_at=2025-11-06 21:48:03`), перечисленных выше. Users 10509/10513, student_tournaments 264/264, education_klass_videos 11/11, education_kata_videos 6/6, kata_competitions_videos 20/20, championships 13/13, tournaments 92/92, posts 34/34. Архивный аудит ожидаемо возвращает exit 1 из-за этих четырёх оригиналов; недостающих ссылок действующих записей не осталось.
- Приватные план, SHA-256-манифесты и результаты проверок сохранены на новом сервере в `<project>/.deployment/media-delta-20260918/`. В журнал v2 добавлено событие `media.s3_delta_copied` с источником, old/new, объёмом и хешем манифеста. Пользовательские записи и старые базы не менялись; сервисы не перезапускались. Это разовая синхронизация ссылок нового дампа, не автоматическое зеркалирование последующих загрузок старого сайта.

<a id="scope-verification"></a>
## Реализация и проверка остаточного scope, 09.09.2026

Этот раздел обновляет прежние снимки проверок ниже. Оставшаяся ручная приёмка и внешние зависимости находятся только в [scope](remaining-scope.md).

### Новые общие правила

- **Видео:** `KataVideoUpload::rules()` используется первым кругом mobile, финальным mobile/web POST и `KataFinalVideoService`. Flutter/Vue проверяют размер до отправки; API повторяет проверку. RU/EN validation сообщает предел 100 МБ. PHP: `upload_max_filesize=100M`, `post_max_size=110M`; nginx: `client_max_body_size=112m`, JSON 413 с `upload_too_large`. Дополнительное место нужно multipart, оно не повышает разрешённый размер самого файла. PHP/nginx перезапущены, `nginx -t`, значения PHP и реальный ответ nginx 413 проверены. MySQL не перезапускалась.
- **Локаль:** `app_locale` хранится в SharedPreferences (это не секрет), восстанавливается до проверки сессии. `ApiClient.locale` задаёт Accept-Language для JSON, multipart, скачивания и авторизованных медиа; native Material/Cupertino-компоненты используют `flutter_localizations`. Web auth-ссылки получают только `locale`, без bearer; `App.vue` учитывает этот параметр. Backend использует `PanelLocale`, `lang/{ru,en}/mobile.php`, `validation.php`, `about.php` и существующий ExportLabels. Переводить подпись ранга, но не перезаписывать сохранённый legacy-ранг ради отображения.
- **«О нас»:** PanelAboutController и MobileAboutController возвращают одинаковую структуру из `lang/{ru,en}/about.php`. Flutter отображает описание, реквизиты и контакты из ответа, показывает ошибку/повтор вместо фиктивных данных. При обновлении реквизитов менять общий источник, не мобильную копию.
- Верхняя иллюстрация двух бойцов в Flutter AboutScreen использует исходный `about-kyokushin.png`, увеличенное соотношение сторон 1.65 и максимальную ширину 430 px. Две градиентные alpha-маски плавно растворяют края в фоне текущей темы без изменения bitmap-файла. Регрессия в `locale_about_profile_test.dart` проверяет 360/393/430 px, RU/EN, обе темы и увеличенный текст; скриншоты светлой/тёмной темы сохраняются через SAVE_SCREENSHOTS.
- **Темы и профиль:** старые экраны используют контекстные `AppColors.inkFor/mutedFor/borderFor/surfaceFor/accentFor`, а не постоянный тёмный текст на тёмной поверхности. Реальные цвета поясов/медалей и белый текст на красной кнопке не заменять цветом темы. Факт-бейджи профиля переходят с 3 на 2/1 колонку при увеличении текста; полная дата, имя и клуб не теряются. Снимки профиля: `karaterating_trainer/build/scope-screenshots/profile-light.png`, `profile-dark.png`.
- **Запросы:** Students/Examinations/Championships/ChampionshipDetail игнорируют устаревшие ответы; номер страницы фиксируется после успеха. Удаление/открепление защищено от повторного нажатия на время подтверждения/запроса. Пагинированные picker и существующие формы сохраняют выбранные ID и ошибки. Эти локальные состояния не заменяют серверную проверку прав и идемпотентность.

### Остаточная permission/контрактная матрица

Ниже указаны реально запущенные наборы, а не утверждение о проверке всех возможных комбинаций. Mobile-профили не открываются другим Coach, публичные сведения сетки не раскрывают документы. Для общей организационной модели важны legacy role_id и model_has_roles.

| Сценарий и граница доступа | HTTP / сервисные проверки | Flutter-проверки |
| --- | --- | --- |
| Coach: вход, отозванная/просроченная сессия, другая роль, согласия | MobileSessionTest, MobileAgreementsTest | session_test, widget_test |
| Свой профиль, разрешённые поля, удаление, RU/EN | MobileProfileTest | locale_about_profile_test, student_workflow_test; прежний profile_consent integration |
| Только свой закрытый ученик/документ; приглашение/код и открепление | MobileStudentsTest | student_workflow_test, protected_media_test |
| Допущенный Coach, свой ученик, разрешение организации, стадия и конкретная заявка | MobileTournamentEnrollmentTest, TournamentListWorkflowTest, ExternalFormWorkflowTest | tournament_enrollment_test |
| Оплата, callback, свой видеофайл/финал, поздний конфликт, rollback | OnlineKataPaymentsTest, KataWorkflowTest | kata_payment_workflow_test, protected_media_test |
| Просмотр списков/команд/сеток и разрешённый экспорт без управления | MobileSpectatorTest | tournament_spectator_test |
| Экзамен своей организации, просмотр других участников, изменение только своих | MobileExaminationsTest | examination_workflow_test |
| Видимость ленты до мутации, свой комментарий, медиа, аудит/rollback | MobileFeedTest | feed_workflow_test |
| Уведомления только получателя; безопасные ссылки, чтение/аудит | MobileNotificationsTest | notification_rating_test |
| Общий рейтинг и зависимые фильтры, без демонстрационных результатов | RatingServiceTest, StudentMedalsTest | notification_rating_test |
| Coach, ресурсные права; только оплаченные работы своих учеников | MobileEducationTest | education_test |

В `TournamentManagementWorkflowTest` добавлена отрицательная HTTP-матрица всех зарегистрированных маршрутов `api/panel/tournaments/{championship}` для чужой Organization/Secretary, Coach, Student, Judge и гостя с проверкой неизменности данных. Положительные действия владельца/его секретаря и специализированные ограничения проверяют PanelAccessSecurityTest и доменные workflow. Это не даёт секретарю новых полномочий.

### Конкуренция и повторяемые команды

- `FightWorkflowTest` генерирует все размеры обычной сетки от 2 до 32 (3 участника проверяются отдельным Round Robin-сценарием), включая промежуточные размеры. Сброс зависимостей, неявки, обмен, призёры, рейтинг и rollback остаются в общих доменных тестах.
- [ConcurrentResultsTest](../tests/MySql/ConcurrentResultsTest.php) запускает два независимых PHP-процесса через [concurrent-result.php](../tests/support/concurrent-result.php) и реальные `FOR UPDATE`: разные колонки оценки не теряются, одна колонка и конфликтующие победители дают второму писателю 409, проверяется аудит. Это ограниченный конкурентный тест, не нагрузочное испытание production.
- MySQL-набор намеренно вне обычного Feature suite. Он запускает `migrate:fresh` **только** при APP_ENV=testing, mysql и имени базы с префиксом `kr_scope_test_`; иначе пропускается. Использовать отдельную пустую базу/пользователя, никогда не переименовывать рабочую базу под этот префикс. Для прогона была создана `kr_scope_test_20260909`, без мутаций рабочей базы и реальных писем/оплат. После прогона временная база удалена, выданные на неё права отозваны; для повторения её нужно создать заново.

```sh
# Из karaterating-v2: изолированный общий suite.
docker compose exec -T -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= -e APP_CONFIG_CACHE=/tmp/kr-no-config.php app php -d memory_limit=512M vendor/bin/phpunit --colors=never
# Только после отдельного создания тестовой MySQL-базы и выдачи прав на неё.
docker compose exec -T -e APP_ENV=testing -e DB_CONNECTION=mysql -e DB_DATABASE=kr_scope_test_20260909 -e DB_URL= -e APP_CONFIG_CACHE=/tmp/kr-no-config.php app php -d memory_limit=512M vendor/bin/phpunit tests/MySql/ConcurrentResultsTest.php --colors=never
```

Подтверждено: **238/3173** SQLite, отдельно **1/20** MySQL, **69** Flutter-тестов; `flutter analyze --no-pub` без замечаний; `npm run build` успешен (прежние auth background URLs разрешаются в runtime). Все 9 `tests/ui/*-workflow.cjs` прошли: account, team, external-form, tournament-management, tournament-list, fight, kata, student-history, panel-export. Их API подменён. Новые video-тесты проверяют границу 102400/102401 КБ и сохранение прежнего файла; они не заменяют реальный кодек/воспроизведение. Flutter проверяет JSON/multipart locale, перезапуск с языком, ошибку/retry About, устаревший ответ списка, профиль на 360/393/430 px, RU/EN, light/dark, масштабы 1/1,6/2.

Не проверялись заново реальные контрольные рейтинги старой базы, доставка внешней почты/push, sandbox-оплата и физический Android. Firebase/APNs отсутствуют, локальные ключи ЮKassa не настроены. Медиа впоследствии восстановлены в S3, см. раздел переноса выше. Push-интеграция остаётся отдельной незавершённой возможностью, а не якобы пройденной визуальной проверкой.

<a id="domains"></a>
## Подробные правила по разделам

Ниже сохранены доменные правила, миграции и проведённые проверки из прежних документов. Числа тестов и сведения «локально применено» в подразделах «Проверки/Развёртывание» являются **датированными снимками этапа**, а не текущим общим статусом. При противоречии старого снимка более позднему разделу руководствоваться актуальным кодом, принятым решением и единым scope.

1. [Доступ и защищённые файлы](#security)
2. [Web: личный кабинет, согласия и восстановление](#account)
3. [Web: команда и код организации](#team)
4. [Анкеты, внешние команды и импорт](#forms)
5. [Чемпионаты, турниры и заявки организаций](#tournaments)
6. [Шаблоны, ранги и заявки участников](#lists)
7. [Сетки, результаты, неявки и Round Robin](#fights)
8. [Ката: оценки, финал, результаты и замена видео](#kata)
9. [Медали, общий рейтинг и история ученика](#medals)
10. [Выгрузки, фоновая генерация и частичные обновления](#exports)
11. [Мобильный вход, сессия и навигация](#mobile-auth)
12. [Мобильный профиль, удаление и согласия](#mobile-profile)
13. [Мобильные ученики, документы и код тренера](#mobile-students)
14. [Мобильный каталог и запись на турнир](#mobile-enrollment)
15. [Онлайн-ката: оплата, возврат и просмотр видео](#payments-video)
16. [Мобильные списки, сетки и быстрые данные](#spectator)
17. [Экзамены и участие тренерских учеников](#exams)
18. [Лента, обсуждения, реакции и медиа](#feed)
19. [Мобильные уведомления и фильтры рейтинга](#notices-rating)
20. [Обучение и оплаченные работы учеников](#education)

<a id="security"></a>
## Доступ и защищённые файлы

**Где читать и переиспользовать:**
- [app/Services/PanelAccess.php](../app/Services/PanelAccess.php); [app/Services/ProtectedMedia.php](../app/Services/ProtectedMedia.php); [app/Http/Controllers/ProtectedDocumentController.php](../app/Http/Controllers/ProtectedDocumentController.php).
- [app/Http/Controllers/PublicMediaController.php](../app/Http/Controllers/PublicMediaController.php); [app/Http/Middleware/ExaminationAccess.php](../app/Http/Middleware/ExaminationAccess.php); [app/Console/Commands/PrivatizeMedia.php](../app/Console/Commands/PrivatizeMedia.php).

Зафиксировано 05.09.2026 при выполнении ACL-01–04. Это действующие правила и инструкция развертывания, не список оставшихся работ.

### Матрица доступа

| Раздел / действие | Организатор | Секретарь | Тренер / ученик |
| --- | --- | --- | --- |
| Судьи и секретари: список, экспорт, создание, изменение, пароль | Только своя организация | Нет | Нет |
| Экзамены: просмотр | Да, в пределах существующих проверок принадлежности | Нет | Да, в пределах существующих проверок принадлежности |
| Экзамены: создание, изменение, удаление | Только своя организация | Нет | Нет |
| Документы пользователя | Своей организации, в том числе через тренера | Своей организации, в том числе через тренера | Тренер: документы собственных учеников; пользователь: собственные документы |

Администратору разрешен просмотр документов. Для экзаменов сохранены старые возможности прикрепления/открепления своих учеников тренером и самостоятельного участия ученика; права секретаря не расширены. Основание: старые `JudgeResource::canAccess`, `SecretaryResource::canAccess`, `ExaminationResource::canViewAny/create/update`.

Общая матрица UI/API задается `app/Services/PanelAccess.php`; экзамены дополнительно защищены middleware `ExaminationAccess`. Скрытая вкладка не заменяет серверную проверку.

### Публичные анкеты

- Присланный `user_id` игнорируется при сохранении, выдаче и импорте. Совпадение ФИО и даты рождения не разрешает изменять существующую учетную запись.
- Сервер выдает UUID строки. Доверенная связь хранится в `external_form_students`, отдельно от публичного JSON: анкета + UUID строки + ученик.
- Импорт доступен организатору и секретарю организации чемпионата, только после закрытия анкеты. Импорт и изменения пользователей выполняются в транзакции с блокировками.
- Повторно обновляется только связанный сервером Student без дополнительных повышенных ролей, все еще принадлежащий нужной организации. Проверяется и организация его тренера.
- Для каждого созданного/обновленного ученика записываются actor, form_id, row_id, признак создания и old/new изменяемых полей.
- Старые публичные `user_id` не переносятся автоматически в доверенные связи. Без явного сопоставления повторный импорт старой анкеты может создать отдельные внешние записи. Подтверждаемое сопоставление старых строк реализовано в разделе анкет; автоматическое присвоение по старому user_id запрещено.

### Хранение и выдача

- Будо-паспорт, марка, страховка, IKO, медсправка и видео онлайн-ката хранятся на disk `protected`, в `storage/app/protected`, вне web-root.
- Web-документы выдаются через `/api/panel/files/users/{id}/{document}` по сессии; мобильные через `/api/mobile/files/users/{id}/{document}` по bearer token. Flutter отправляет токен только на защищенный файловый маршрут своего API origin.
- Видео выдается существующим обработчиком `showOnlineKataVideo` с проверкой владельца/доступа к турниру. API ката возвращает `video_uploaded` и авторизованный `video_url`, а не публичный путь.
- Ответы защищенных файлов имеют `Cache-Control: private, no-store`. Пути проверяются на traversal и выход за корень через symlink.
- `/storage/...` проходит через `PublicMediaController`. Документы и видео недоступны по этим ссылкам даже до физического переноса, включая нестандартные пути из старой БД и записи soft-delete. Аватары и изображения чемпионатов остаются публичными.
- Nginx передает весь `/storage/` Laravel; автоматический `serve` локального disk отключен, `filesystems.links` пуст. Нельзя вновь создавать `public/storage` symlink или публичный alias к приватному хранилищу.
- Авторизованная выдача временно поддерживает старый файл на public disk, если приватной копии еще нет. Это не открывает прямую публичную ссылку.

### Развертывание

1. Сделать резервную копию БД и файлов. Развернуть backend и собранный Vue frontend; обновить мобильный клиент для авторизованного просмотра документов.
2. Применить миграцию `2026_09_05_170000_create_external_form_students_table.php`.
3. Применить конфигурации filesystem/nginx, сбросить ранее собранные config/route caches при их использовании. Перечитать nginx до переноса файлов, чтобы перекрыть старые статические ссылки.
4. Выполнить `php artisan media:privatize` для аудита. Затем `php artisan media:privatize --apply`: копирование на private disk, проверка SHA-256, удаление публичной копии только после проверки. Команда также удаляет `public/storage`, только если это symlink.
5. При несовпадающих копиях команда прерывается и сохраняет публичный оригинал. Не удалять его вручную до разбора. Повторный запуск безопасен. Каталоги-дубликаты, CDN и внешние копии вне этого deployment проверяются отдельно; они не контролируются данным nginx.
6. `missing` означает, что оригинал отсутствовал до переноса. Полученные позднее файлы следует сразу размещать на private disk с прежними относительными путями; нельзя восстанавливать общедоступный storage alias.

В первоначальном ACL-этапе миграция была применена, nginx перечитан, symlink удалён; исторический аудит дал public=0, private=0, moved=0, missing=8431, invalid=0. Этот результат относился к пустому локальному хранилищу. **09.09.2026 оригиналы восстановлены в приватный S3**, актуальная сверка и четыре ссылки удалённой записи описаны в разделе переноса выше; прежняя блокировка QA-07 закрыта.

### Проверки

- Backend: 23 теста, 187 assertions, SQLite in-memory без изменения рабочей MySQL. Включены положительные и отрицательные HTTP-сценарии прав, подмена идентификатора и ФИО в анкете, повторный импорт, смена организации, защита обоих видеокругов, документов, миграция с конфликтом контрольных сумм.
- Vue production build проходит. Flutter-тест `protected_media_test.dart` проходит; анализ затронутых мобильных файлов не добавил ошибок, остаются ранее существовавшие info-замечания.
- Проверено на nginx :8080: существующий синтетический файл в `passport/` возвращает 404 по прямому URL; публичное изображение чемпионата возвращает 200. Синтетический файл удален.
- Полный браузерный прогон всех ролей и просмотр отсутствующих реальных файлов не заявляются выполненными; оставшаяся приемка находится в основном scope.
### Мобильное видео онлайн-ката (08.09.2026)

GET /api/mobile/files/kata/{pool}/{student} выдаёт только видео соответствующего круга своему назначенному тренеру, по bearer и с поддержкой Range. Ожидающие оплаты файлы также приватные. Долговечная заявка, ограничения повторов и очистка: [Онлайн-ката: оплата, возврат и просмотр видео](#payments-video).
### Мобильное обучение (09.09.2026)

Каталоги и оплаченные работы учеников выдаются через /api/mobile/files/education/... с проверкой Coach, view_any/view ресурсных прав; для работ дополнительно is_payment и собственный действующий ученик. Снятие допуска/открепление закрывает последующие файловые запросы. Оценки, комментарии и рекомендации до is_review не возвращаются.

ProtectedMedia и media:privatize учитывают education_kata_videos, kata_competitions_videos (path/poster_path) и education_klass_videos.path. Прямые публичные ссылки этих записей и все пути video/kata-klass, videos/education-kata закрыты, в том числе пока оригиналы находятся на public disk. Нативный player использует заголовок bearer, без токена в URL. Правило private/no-store, Range и защита от traversal сохранены.

Исходных 24 учебных видео локально нет; --apply не запускался и восстановление не объявляется выполненным. См. [Обучение и оплаченные работы учеников](#education) и EDU-MEDIA в mobile scope.

<a id="account"></a>
## Web: личный кабинет, согласия и восстановление

**Где читать и переиспользовать:**
- [app/Http/Controllers/Panel/Account/](../app/Http/Controllers/Panel/Account/); [app/Http/Controllers/Auth/PasswordRecoveryController.php](../app/Http/Controllers/Auth/PasswordRecoveryController.php); [app/Services/Account/Agreements.php](../app/Services/Account/Agreements.php).
- [app/Services/Account/SafeContent.php](../app/Services/Account/SafeContent.php); [resources/js/pages/panel/AccountProfilePage.vue](../resources/js/pages/panel/AccountProfilePage.vue); [resources/js/pages/PasswordRecoveryPage.vue](../resources/js/pages/PasswordRecoveryPage.vue).

Реализовано 05.09.2026, NAV-01–05. Старые источники: `Profile`, `UserAlert`, `Agreement`, `ConsentGate`, `CheckUserAgreement`, `Auth/PasswordReset`.

### Принятый объём профиля

- По уточнению пользователя Organization редактирует только название и аватар; Secretary только имя, фамилию и аватар.
- Документы, реквизиты, спортивные поля, настройки уведомлений и самостоятельное удаление аккаунта из старого Profile в этот упрощённый профиль не переносились. Это изменение объёма по текущему запросу, а не заявленная полная копия старой страницы.
- `/panel/profile`, GET/POST `/api/panel/account/profile`. Идентификатор берётся из сессии; смена email, организации, роли и чужого пользователя через запрос невозможна.
- Аватар: JPEG/PNG/WebP до 4 МБ и 4096×4096; замена/удаление, очистка собственного прежнего файла после успешной транзакции, очистка нового файла при ошибке. Старые потенциально общие аватары не удаляются автоматически.

### Уведомления

- `/panel/notifications`; индивидуальные связи `user_alert_user.read_at`, пагинация 20 записей, одиночная и общая отметка прочитанного без изменения чужих связей. Повторная отметка не меняет первоначальное время чтения.
- Колокольчик со счётчиком, обновление после действий, при возвращении фокуса и раз в минуту в открытой панели.
- В отличие от старого автоматического прочтения всего при mount, отметка здесь явная: открытие списка само по себе не делает невидимые страницы прочитанными.
- HTML очищается на сервере белым списком элементов и ссылок: без скриптов, обработчиков, внедрённых форм/iframe. Сохраняются безопасное форматирование и переходы; новые вкладки защищены `noopener noreferrer`. Внутренние действия по-прежнему проходят ACL соответствующего API.

### Соглашения

- `/panel/documents` и `/panel/documents/{id}`; совместимый просмотр `/panel/agreement-doc/{id}`. Названия трёх старых типов локализованы RU/EN. Только чтение и согласие, без административного CRUD.
- Как в старом ConsentGate, обязательны документы 2 (конфиденциальность) и 3 (персональные данные), если они существуют в БД. Остальные документы доступны для чтения и отдельного согласия.
- Пока актуальные обязательные согласия не приняты, SPA отправляет на документы, а JSON API панели возвращает 409 `agreements_required`; доступны только обработчики соглашений, auth и выход. Роли вне Organization/Secretary этим web-gate не изменены.
- Согласие требует явного checkbox и hash показанной версии. Транзакция сохраняет пользователя, ID, тип, hash, исходный текст и время; уникальный индекс исключает дубли. При смене текста/типа нужно новое согласие; устаревшее подтверждение отклоняется.
- Старые `success_politic`/`data_processing` обновляются для совместимости, но не выдаются за доказательство согласия с конкретной версией. Поэтому при первом входе после переноса Organization/Secretary повторно подтверждают актуальные документы.

### Восстановление пароля

- «Забыли пароль» → `/forgot-password`, письмо → `/reset-password?token=...&email=...`; POST `/api/auth/forgot-password`, `/api/auth/reset-password`.
- Стандартный Laravel broker: хешированный токен в БД, 60 минут, одно использование; отдельные лимиты 5/мин для запроса и 10/мин для смены, повторное письмо на аккаунт не чаще 60 секунд. Ответ запроса одинаков для существующего и неизвестного email.
- Письмо использует настроенный `MAIL_MAILER=resend`, очередь `deferred` после ответа. URL строится из доверенного `APP_URL`, не заголовка Host. Для локального окружения сейчас `http://localhost:8080`.
- Пароль от 8 символов с подтверждением; после смены отзываются web-сессии, remember token и API/mobile-токены, включая текущую авторизованную сессию. Пароли и reset-токены в журнал не пишутся.
- Resend выбран и ключ присутствует. Тесты используют `Mail::fake`; фактическая доставка в почтовый ящик не проверялась, реальных писем при проверке не отправляли.

### Дашборд и ссылки

- `/panel/dashboard`: тренеры, ученики тренеров организации, ожидающие приглашения, личные непрочитанные уведомления, шесть ближайших турниров своей организации. Для Secretary организация берётся от связанной Organization.
- Счётчики ведут в соответствующую вкладку команды или уведомления; турниры в свои страницы, ссылка чемпионатов рабочая. Удалённые турниры/чемпионаты не включаются. Для выборки добавлен составной индекс организации/удаления/даты/ID.
- Profile-pill открывает профиль. В подписи реальные роли RU/EN. Dashboard и Documents подключены; при входе и переходе на корень уже авторизованный пользователь получает рабочую панель (либо обязательные согласия).
- Все новые экраны Vue/JSON, единые стили и обработка ошибок, светлая/тёмная тема. Бизнес-права остаются на сервере.

### Журнал и проверки

- События: `profile.updated`, `notification.read`, `agreement.accepted`, `password.recovery_requested` (анонимный инициатор), `password.reset`, `user.login`, `user.logout`. Для изменений записаны old/new и нужные идентификаторы; секреты исключены.
- `tests/Feature/AccountWorkflowTest.php`: 10 сценариев. Полный backend-прогон: 44 теста, 408 проверок, SQLite in-memory, без очистки рабочей MySQL.
- `tests/ui/account-workflow.cjs`: изолированный Chrome и фикстурные API. Дашборд, сохранение/повторная загрузка профиля, счётчик уведомлений, consent-gate, поля секретаря, восстановление, светлая/тёмная тема, desktop 1280 и mobile 390, отсутствие горизонтального overflow и JS-ошибок.
- На подключённой MySQL выполнены только чтения новых обработчиков; все четыре вернули 200. Миграции добавляют таблицу согласий и индексы, не заменяют старые данные. `npm run build` проходит.

<a id="team"></a>
## Web: команда и код организации

**Где читать и переиспользовать:**
- [app/Services/Team/OrganizationInvitations.php](../app/Services/Team/OrganizationInvitations.php); [app/Services/Team/AcceptTrainerInvitation.php](../app/Services/Team/AcceptTrainerInvitation.php); [app/Services/Account/RegistrationChallenge.php](../app/Services/Account/RegistrationChallenge.php).
- [app/Http/Controllers/Panel/TeamController.php](../app/Http/Controllers/Panel/TeamController.php); [app/Http/Controllers/Panel/TeamMemberDeletionController.php](../app/Http/Controllers/Panel/TeamMemberDeletionController.php); [resources/js/pages/TrainerRegistrationPage.vue](../resources/js/pages/TrainerRegistrationPage.vue).

Решение от 05.09.2026: вместо старых реферальных ссылок используется постоянный уникальный код организации. TEAM-01–04 выполнены и удалены из списка оставшихся работ.

### Код и регистрация

- Код хранится отдельно в `organization_join_codes`, защищен уникальным индексом и создается при первом обращении организации к нему. В разделе «Команда» организатор и его секретарь видят один и тот же код и могут скопировать его.
- С 14.09.2026 письмо содержит код организации и инструкции регистрации в общем приложении, без ссылки `/panel/register`, `ref` и email в URL. Ссылки установки iOS/Android берутся из `config/mobile_app.php` через `MobileAppLinks`; старые web-формы оставлены для совместимости, но приглашения и Flutter на них не направляют.
- Приглашение сохраняет `organization_id` независимо от отправителя. Приглашение секретаря относится к его организации, остается у нее после удаления/смены организации секретаря. «Ожидают», повторная отправка и отзыв доступны в рамках организации.
- Для регистрации тренера достаточно действительного кода организации и собственного email: предварительное письмо-приглашение не требуется. `OrganizationInvitations::organizationByCode` проверяет живую организацию и роль, `pending` переиспользует ожидающее приглашение либо создает заявку с `source=registration` в журнале. Код постоянный и пригоден для разных тренеров. Подтверждение заявки одноразовое; отзыв ожидающей заявки отменяет текущую попытку подтверждения, но не отзывает общий код организации.
- Новый аккаунт: имя, фамилия, пароль и повтор пароля. Существующий аккаунт: email и действующий пароль; данные и пароль аккаунта не перезаписываются. Принимается только Coach без других ролей, без организации либо уже в целевой организации. Автоматического переноса из чужой организации нет.
- Перед созданием/привязкой аккаунта тренер подтверждает владение email шестизначным кодом из отдельного письма. Срок 10 минут, максимум 5 проверок одного кода, endpoints ограничены по частоте. До подтверждения аккаунт не создается и организация не меняется.
- Принятие выполняется в транзакции с блокировкой организации, приглашения и аккаунта. Код организации, статус приглашения, роль и принадлежность аккаунта проверяются повторно. `confirmed` и `accepted_user_id` заполняются сервером; старые дубликаты приглашений этой организации на тот же email потребляются вместе.
- Создание аккаунта, принятие приглашения и вход журналируются без паролей и кодов подтверждения. После успеха сессия обновляется, тренер переходит в `/panel/tournaments`.
- «Ожидают» обновляется после действий, при возвращении фокуса на вкладку и раз в 30 секунд на видимой странице. Подтвержденное приглашение исчезает из ожидающих, тренер появляется в команде.

### Остальные действия

- Организатор удаляет своего судью/секретаря из строки, редактора либо массовым выбором текущей страницы. Во всех вариантах есть подтверждение. Смешанный набор с чужой/недоступной записью отклоняется целиком без частичного удаления.
- Используется soft-delete: история и внешние ключи сохраняются. Другие роли и связанные ученики блокируют удаление. Сессии и мобильные токены удаляемых аккаунтов отзываются; каждый аккаунт имеет отдельную запись old/new в журнале.
- По решению владельца от 14.09.2026 в web-просмотре тренера для организатора/секретаря блок документов (паспорт, марка, страховка, IKO) не показывается. Сохранённые файлы не удаляются; документы учеников и проверки их доступа этим изменением не затронуты.
- Фото в списках тренеров, учеников и учеников тренера используют `.team-avatar-cell`: колонка вмещает аватар с отступами, текстовое троеточие в этой ячейке отключено. Для остальных текстовых колонок обрезка сохранена.
- `capabilities.detach_students` приходит из API: кнопка открепления видна только организатору. Запрет секретарю остается и на сервере.

### Развертывание и проверки

- Применена миграция `2026_09_05_180000_add_organization_invitation_codes.php`: таблица кодов, привязка приглашений к организации, ссылка на принявший аккаунт и индекс поиска приглашения. Старые приглашения сохранены и получили организацию отправителя.
- Письма отправляются через настроенный mailer в Laravel deferred queue после ответа; приглашения ставятся в очередь только после успешной транзакции. При сбое доставки можно повторить отправку. Настройки внешнего почтового провайдера не менялись.
- Backend: 34 теста / 275 assertions в SQLite in-memory, без тестовых изменений рабочей MySQL. В тестах почта подменена `Mail::fake`; реальные письма не отправлялись и доставка через внешний почтовый сервис не проверялась.
- Браузерный `team-workflow` с синтетическими API-ответами проверяет форму, скрытый пароль, мобильную ширину 390px, переход к подтверждению email, существующий аккаунт, подтверждение массового удаления, обновление строк и запрет кнопки секретарю. Регрессии интерфейса: отсутствие блока документов тренера у обеих ролей и отсутствие обрезки аватарок в списках на 1280/360/393/430 px. Реальный backend проверяется отдельно HTTP feature-тестами.
- Для запуска браузерного теста: `PLAYWRIGHT_MODULE=/path/to/playwright node tests/ui/team-workflow.cjs`; по умолчанию используется установленный Chrome и сервер `http://127.0.0.1:8080`. Доступны overrides `PLAYWRIGHT_CHANNEL` и `TEST_BASE_URL`.

Отсутствующие исходные файлы локальной копии не восстанавливались; это остается QA-07 основного scope. Для приглашения ученика действует реализованный позднее уникальный код тренера; см. раздел мобильных учеников.

<a id="forms"></a>
## Анкеты, внешние команды и импорт

**Где читать и переиспользовать:**
- [app/Services/Tournaments/ExternalFormImportService.php](../app/Services/Tournaments/ExternalFormImportService.php); [app/Services/Tournaments/ExternalFormRows.php](../app/Services/Tournaments/ExternalFormRows.php); [app/Services/Tournaments/ExternalParticipantIdentity.php](../app/Services/Tournaments/ExternalParticipantIdentity.php).
- [app/Services/Tournaments/ExternalFormStudentLink.php](../app/Services/Tournaments/ExternalFormStudentLink.php); [app/Services/Tournaments/ExternalFormApplications.php](../app/Services/Tournaments/ExternalFormApplications.php); [app/Jobs/ImportExternalForm.php](../app/Jobs/ImportExternalForm.php).
- [resources/js/pages/panel/ExternalFormEditorPage.vue](../resources/js/pages/panel/ExternalFormEditorPage.vue).

Реализовано 05.09.2026: FORM-01–FORM-06. Старый `app/Filament/Pages/ExternalForm.php` использован как референс полей и сценариев, но глобальный поиск/обновление пользователей из публичной анкеты не перенесены.

### Редактирование

- В чемпионате действие «Участники анкеты» открывает `/panel/tournaments/{championship}/forms/{form}`.
- Владелец Organization и Secretary его организации видят все строки: поиск и страницы по 20 участников. В модалке доступны ФИО, пол, дата рождения, возраст, вес, кю/дан, регион, город, клуб, ФИО тренера, категории, номер группы, разряд, лучшие результаты.
- Разрешены добавление, изменение и удаление строк после закрытия записи. Публичная форма при этом остаётся закрытой.
- Сохранение проверяет ревизию анкеты, чужое параллельное изменение возвращает 409. Публичный `user_id` не принимается. Новые UUID строк назначаются сервером.
- Личное и групповое балльное ката выбираются независимо. Старое сочетание `kata_point` + номер группы нормализуется в `kata_group`; обе категории в новой форме сохраняются отдельно.

### Участники и тренеры

- Доверенная связь строки и ученика хранится в `external_form_students`, а не в публичном JSON. Совпадение ФИО/даты рождения ищется только внутри той же анкеты с тем же клубом/тренером. Чужие глобальные совпадения не используются.
- Для внешней команды создаётся тренер с ролью Coach, организацией владельца и `is_external=true`. Связь «анкета + ФИО тренера + клуб» хранится отдельно. Ученик имеет `coach_id`; его клуб берётся только из тренера.
- Внешние записи не являются доступными для входа аккаунтами: случайный пароль, технический email, запрет web/mobile-входа, восстановления пароля и принятия тренерского приглашения. В команде есть отметка внешней команды, технический email скрыт.
- Обычные проверки доступа к ученику продолжают действовать: тренер и ученик принадлежат организации. Глобальный просмотр чужих учеников не открывается. Внешний тренер прикрепляется к турниру и попадает в выбор тренеров и выгрузку учеников.
- Для старых анкет без серверной связи есть действие «Связать с участником чемпионата», поиск и подтверждение выбранной записи. Кандидаты ограничены Student своего чемпионата и организации. Старую запись без организации можно принять только при отсутствии тренера и подтвержденного email, точном старом техническом email `karaterating{id}@karaterating.ru` и отсутствии участия в турнирах чужих организаций. Сам старый `user_id` ничего не разрешает. Неоднозначные или чужие записи не присваиваются автоматически.

### Заявки и группы

- `external_form_applications` различает анкету, строку, категорию, турнир и фактическое прикрепление к списку. Один ученик может одновременно участвовать в личном и групповом ката одного турнира.
- `external_form_groups` сохраняет UUID по анкете, турниру и номеру группы. Позднее добавленный член получает тот же UUID. Старые фрагментированные группы при однозначной серверной связи согласуются с этой идентичностью.
- Для группы подбирается один список, в который по возрасту на день комиссии помещается весь состав (`TournamentAge`). `gender=null`, пустое значение и `all` означают отсутствие ограничения пола. Иначе проверяется каждый участник. При отсутствии подходящего списка весь состав идёт в групповой fallback, а не разбивается по возрастам.
- Повторный импорт не создаёт дублей и удаляет исчезнувшие заявки, созданные этой анкетой. Прикрепления вне анкеты сохраняются с отдельной отметкой в отчёте. При ошибках строк прежние заявки не удаляются; изменение групп откладывается до исправления ошибок.
- Сгенерированные пули/таблицы и завершённые турниры не изменяются импортом. Неизменившуюся заявку можно повторно проверить без мутации. Заблокированные изменения попадают в отчёт.

### Личные данные и отчёт

- По умолчанию изменения заявки не перезаписывают личные данные ранее связанного ученика. Отличия отмечаются в отчёте.
- Отдельный флажок подтверждения разрешает обновить профиль только внешнего ученика с внешним тренером своей организации. Обычные учётные записи защищены от такой перезаписи.
- Импорт возвращает 202 и идентификатор запуска; задание выполняется через Laravel deferred queue после ответа. Повторный клик не ставит второй параллельный запуск той же анкеты. Проверяются права, закрытая запись и ревизия непосредственно при выполнении.
- Состояния: queued, running, completed, failed. Результаты и ошибка запуска сохраняются в БД; отчёт по строкам имеет пагинацию. В нём показаны добавления, удаления, неизменившиеся заявки, защищённые прикрепления, ошибки полей, отсутствующий турнир, проблемы группы и отличия профиля.
- Изменения анкеты, создание/сопоставление/изменение ученика, создание/прикрепление тренера, добавление/удаление/согласование заявки и итог запуска имеют отдельные события `activity_log` с исполнителем, контекстом и old/new. Публичное сохранение отмечается источником `public_form`.
- Импорт и сохранение успешного результата выполняются транзакционно; ошибки отдельной строки или группы изолируются savepoint. Схема добавлена миграцией `2026_09_05_200000_extend_external_form_imports.php`.

### Проверки

- `ExternalFormWorkflowTest`: 13 сценариев, 106 assertions; полный набор проекта: 57 тестов, 514 assertions в SQLite in-memory.
- Проверены закрытый редактор на 45 строках, права и конфликт ревизий, обе категории, null-пол группы, добавление/удаление состава, повторный импорт, совпадения только внутри анкеты, отдельное подтверждение профиля, невалидные строки, fallback, защита завершённых заявок, очередь/отчёт, старое сопоставление, фрагментированные UUID, профиль и выгрузка по внешнему тренеру.
- `tests/ui/external-form-workflow.cjs`: пагинация, редактирование и закрытие модалки, две категории, подтверждение связи, запуск/отчёт, desktop и 390px, светлая/тёмная тема, отсутствие горизонтального overflow. Использованы тестовые API-ответы без изменения общей БД.
- Миграция применена к локальной MySQL без повторного импорта реальных анкет. Сопоставление старых строк остаётся явным действием владельца, не автоматической массовой операцией.

<a id="tournaments"></a>
## Чемпионаты, турниры и заявки организаций

**Где читать и переиспользовать:**
- [app/Http/Controllers/Panel/TournamentController.php](../app/Http/Controllers/Panel/TournamentController.php); [app/Http/Controllers/Panel/Tournaments/](../app/Http/Controllers/Panel/Tournaments/); [app/Services/Tournaments/TournamentLifecycle.php](../app/Services/Tournaments/TournamentLifecycle.php).
- [app/Services/Tournaments/TournamentBulkActions.php](../app/Services/Tournaments/TournamentBulkActions.php); [app/Services/Tournaments/TournamentAssetUpdate.php](../app/Services/Tournaments/TournamentAssetUpdate.php); [app/Services/Tournaments/OrganizationTournamentApplications.php](../app/Services/Tournaments/OrganizationTournamentApplications.php).
- [resources/js/pages/panel/TournamentDetailPage.vue](../resources/js/pages/panel/TournamentDetailPage.vue).

Реализовано 08.09.2026: TOUR-01–TOUR-06 из аудита организатора/секретаря.

### Чемпионат и файлы

- Организация-владелец и её секретарь редактируют название и постер чемпионата. Чужая организация, тренер и ученик не получают такое право.
- Поля соответствуют старому `ChampionshipResource`: название и изображение. При обновлении без нового изображения остаётся прежнее.
- Турнир поддерживает замену и отдельное удаление положения, заявления и логотипа отчёта. Форма отправляет multipart с метод-override PUT; обычные поля и файлы сохраняются одной операцией.
- `TournamentAssetUpdate` сохраняет новые файлы, выполняет изменение модели и activity_log в транзакции. При ошибке удаляет новые файлы; старые удаляет после commit, только когда на них больше нет ссылки из соответствующего поля, включая архивные записи.
- Назначение организации не принимается из формы. Изменения названия, постера, полей и путей файлов фиксируются с old/new и действующим пользователем.

### Удаление и массовые действия

- Удаление отдельного турнира доступно владельцу/секретарю на карточке в чемпионате и внутри турнира, через подтверждение. Удаление мягкое: участники, заявки, списки, файлы, сетки и результаты сохраняются; рабочие списки, каталог заявок, счётчики и выгрузка участников чемпионата исключают удалённый турнир.
- Как в старом DeleteAction, архивировать можно и завершённый турнир. Это не предоставляет право менять результаты завершённого турнира.
- Массовые открепления тренеров/списков и удаление анкет работают через выбор записей и подтверждение. В одной операции до 200 уникальных ID. Недоступная запись отменяет всю операцию, без частичного успеха.
- При откреплении тренера участники и результаты сохраняются. Владелец может открепить тренеров, подключённых по принятой заявке другой организации; поштучный и массовый сценарии используют один сервис.
- Список с участниками, первичными ссылками заявок или сгенерированными пулями/ката-таблицами открепить нельзя. Правила LIST-04/05 не меняются.
- Удаление анкет не удаляет уже импортированных учеников или их участие. Операция запрещена при queued/running импорте и когда в чемпионате есть турниры, но все завершены. Для чемпионата без турниров удаление анкет разрешено.
- Все массовые операции транзакционны, логируют выбранные ID и прежние/новые значения. После успеха Vue обновляет данные через API, сохраняя вкладку. При ошибке подтверждение остаётся открытым с выбранными записями.

### Заявки организаций

Старый `send_application` и `OrganizationTournamentResource` использовали строки `accepted/canceled`, но own-only выборки делали часть пути недостижимой. Это не основание открывать заявителю все чужие данные.

- В обзоре редактирования турнира добавлен выключенный по умолчанию флаг «Принимать заявки других организаций». Сам по себе флаг не меняет существующие разрешения тренеров или учеников.
- Отдельная страница `/panel/tournaments/applications`: доступные турниры, входящие и свои заявки. Каталог содержит только опубликованные для заявок чужие турниры до окончания комиссии. Показывает название, чемпионат, даты, адрес и стоимость, без чужих участников, документов или управления.
- Отправить заявку может Organization, как в старой версии; Secretary видит каталог, но не отправляет заявку от себя. Организация-владелец и её секретарь принимают/отклоняют входящие заявки своего турнира.
- Повторный запрос не создаёт дубликат. Используются статусы pending/accepted/canceled; старый null отображается как pending. Значение `1`/boolean не считается принятием. Существующие дубликаты одной пары при решении получают единый статус.
- После accepted заявитель и его секретарь видят ограниченный обзор турнира и список своих тренеров с поиском и серверной пагинацией. До комиссии могут подключать собственных тренеров; тренеры далее используют существующий сценарий прикрепления своих учеников с прежними разрешениями.
- Принятие не даёт доступ владельца: нельзя редактировать/удалять чужой турнир, управлять его списками, анкетами или результатами. Общий detail API владельца не расширен.
- Связи тренеров, созданные заявителем, имеют `organization_application_id`. Отклонение снимает только эти связи. Тренеры, ранее подключённые владельцем, участники и результаты сохраняются. Заявитель не может удалить связь, созданную владельцем.
- Решение можно изменить, пока турнир активен. Отправка и решение создают индивидуальные web-уведомления владельцу/заявителю с непрочитанным read_at; действия также пишутся в activity_log.
- Удалённые турниры/чемпионаты не появляются в каталоге и в истории доступных заявок. Принятая заявка не обходит архивирование.

### Даты

- `TournamentLifecycle`: турнир активен включительно до конца `date_finish` в `config('app.timezone')`. В 00:00 следующего дня операции закрываются. Статус списков и capability в detail API согласованы.
- `date_commission` остаётся точным datetime: комиссия открыта до указанного момента включительно, но не позже завершения турнира. Валидация разрешает время комиссии в последний день и отклоняет дату позже этого дня.
- Редактирование, открепление, изменение результата и генерация ограничены активностью. Просмотр владельцем остаётся доступен после завершения, в том числе ката-таблиц.
- Массовая генерация/перегенерация закрывается после комиссии и в UI, и в API. Генерация конкретного списка доступна до конца турнира. Расчётная логика сеток и ката вынесена в отдельные реализованные сервисы; см. разделы сеток и ката. Массовая генерация позднее переведена на panel_tasks, см. раздел выгрузок.

### Проверки и запуск

- `tests/Feature/TournamentManagementWorkflowTest.php`: 12 проверок новых сценариев. Вместе с остальными тестами: 81 тест, 733 assertions в SQLite in-memory. Общая рабочая БД для тестовых данных не использовалась.
- Проверены владельцы/секретари и запреты чужим ролям, архивирование с сохранением связей, файлы/компенсация/общий путь, атомарность bulk, занятый импорт, границы комиссии и завершения, заявки/уведомления, отказ от прав владельца и сохранность результатов после отклонения.
- `tests/ui/tournament-management-workflow.cjs`: multipart, редактирование чемпионата, подтверждения, восстановление после ошибки, обновление списков, сохранение вкладки, заявки/подключение команды, desktop/mobile и темы. API в браузерных проверках подставной; это не тест отправки реальных уведомлений или изменения рабочей БД.
- Стили `.championship-item-actions` применяются только к непосредственным кнопкам/ссылкам карточки, включая мобильный слой. Вложенная модалка удаления использует стандартные размеры крестика и кнопок подтверждения. Этот же UI-тест проверяет открытие из списка турниров, размеры крестика, отсутствие переполнения и закрытие без удаления/перехода на 1440/360/393/430 px.
- Миграция `2026_09_08_100000_add_tournament_application_visibility` применена локально: флаг публикации, индекс пары турнир/заявитель, источник прикрепления тренера. Существующие заявки не переопределяются, существующие турниры автоматически не публикуются.
- Vite-сборка обновлена. На другом окружении нужны эта миграция и `npm run build`; перезапуск Docker локально не нужен.

<a id="lists"></a>
## Шаблоны, ранги и заявки участников

**Где читать и переиспользовать:**
- [app/Services/Tournaments/TemplateListInput.php](../app/Services/Tournaments/TemplateListInput.php); [app/Services/Tournaments/ListRankCriteria.php](../app/Services/Tournaments/ListRankCriteria.php); [app/Services/Tournaments/ListCompatibility.php](../app/Services/Tournaments/ListCompatibility.php).
- [app/Services/Tournaments/TournamentApplications.php](../app/Services/Tournaments/TournamentApplications.php); [app/Services/Tournaments/StudentTournamentListAssignmentService.php](../app/Services/Tournaments/StudentTournamentListAssignmentService.php); [app/Services/Tournaments/TournamentListProgressService.php](../app/Services/Tournaments/TournamentListProgressService.php).
- [resources/js/components/panel/ListRankSelect.vue](../resources/js/components/panel/ListRankSelect.vue).

Актуально на 05.09.2026. LIST-02–LIST-06 выполнены. LIST-01 отменён по решению пользователя: редактирование общего шаблона непосредственно из турнира не добавляется.

### Диапазоны ранга

`TemplateListInput` используется в общем каталоге и при создании списка в турнире. Обе границы сохраняются в исходном порядке; ограничения возраста и веса остаются возрастающими.

- Для `from > to` действует включительный диапазон: например, `8 → 4` допускает 8, 7, 6, 5 и 4 кю.
- Для `from <= to` сохранена старая пороговая ветка: числовой ранг должен быть не меньше `to`. Например, `4 → 8` допускает 8–10 кю, а не 4–8. Значения существующих шаблонов не переворачиваются.
- Ранг ученика `0 кю` нормализуется в `10 кю`, как белый пояс в старом подборе.
- Дан не считается одноимённым кю: `1 дан` не равен `1 кю`. Даны 1–10 представлены старшей границей `0`; например, `8 → 0` включает даны, `10 → 1` исключает их. Число 0 в границе шаблона и строка ученика `0 кю` имеют разные значения; форма явно предлагает «Дан» и «10 кю / 0 кю».
- Неизвестный ранг не проходит ранговый фильтр. Обе пустые границы означают отсутствие фильтра. Балльное личное и групповое ката не фильтруются по рангу или весу.
- Старые числовые границы до 100 допускаются и сохраняются. Служебные fallback-списки исключены из обычных кандидатов и используются только после неудачного подбора.

Основание старой пороговой семантики: `StudentsRelationManager::normalizeRank/studentMatchesListCriteria`, формы `TemplateStudentListResource`.

### Совместимость

`ListCompatibility` применяется при создании, прикреплении шаблонов, автоподборе и ручном переносе. Кумитэ использует только кумитэ, флажковое ката только `flag`, балльное ката разделяет `personal` и `group`. Варианты прикрепления списков отфильтрованы той же политикой. Смешанный запрос с несовместимым или чужим шаблоном отклоняется целиком.

Общий шаблон нельзя изменить на несовместимый с уже связанными турнирами тип. При наличии участников запрещена смена типа заявки. Нельзя удалить общий шаблон, пока он прикреплён к турнирам: каскадное удаление не должно уничтожать заявки и результаты.

### Вес, перенос и открепление

Источник конкретной заявки — `tournament_student_lists.id`, передаваемый как `membership_id`. `student_tournaments` остаётся записью участия и хранит совместимый основной указатель списка.

- При нескольких заявках API требует выбрать конкретную; чужой ID не принимается. В общем списке видны все заявки, в списке конкретной категории выбор предзаполнен. При изменении веса в кумитэ также можно выбрать заявку.
- В ката изменение веса обновляет только вес ученика, не пересобирает личные или групповые заявки.
- В кумитэ меняется только выбранная личная связь. Подбор не затрагивает другие заявки. При отсутствии подходящего списка используется совместимый fallback.
- Перенос групповой заявки переносит всю группу в турнире, сохраняя UUID и ID связей. Открепление групповой заявки открепляет всю группу; личные заявки остаются. Перед действием интерфейс явно показывает этот смысл и требует подтверждения.
- При откреплении удаляются соответствующие связи импорта. При переносе ID заявок и связи импорта сохраняются. Запись участия удаляется только после удаления последней заявки; иначе основной указатель обновляется.
- Перенос/открепление и пересборка групп списка с существующими `pools` или `kata_pools` запрещены на стороне источника и назначения. Исключение для добавления новой личной офлайн-заявки согласовано 17.09.2026: исходный список можно дополнить, готовая сетка/таблица остаётся неизменной до явной перегенерации. Онлайн-ката сохраняет прежний запрет. Не выполняется скрытая перегенерация, удаление боёв или сброс оценок. Открепление даже пустого списка с готовой таблицей защищено отдельно.
- Изменения выполняются транзакционно с блокировкой турнира и журналом `old/new`, идентификатором турнира, ученика и группы. При отказе изменение веса также откатывается.

### Прикрепление

Права организатора и секретаря не расширены: прикрепление группы доступно только в офлайн-балльном ката своего турнира. Выбираются 2–3 ученика одной возрастной категории.

`GET .../student-attach-options` выполняет серверный поиск по словам ФИО и пагинацию по 30 записей. Ограничение первыми 300 удалено. Клуб берётся от тренера; недоступные и уже имеющие групповую заявку ученики исключаются. Выбор сохраняется между страницами и поисковыми запросами.

При отправке проверяются все ID, роль ученика, тренер организации, отсутствие повторной групповой заявки и возрастная категория. Проверка доступности повторяется внутри транзакции. После успеха окно закрывается и данные перезагружаются через API; при ошибке окно и выбор сохраняются.

### Проверки

- `tests/Feature/TournamentListWorkflowTest.php`: обе ветки ранга, белый пояс/даны, совместимость, fallback, сохранение личных/групповых заявок, вес, UUID, запрет изменений готовых сеток/таблиц, принадлежность ID, секретарь, поиск после 300, повторное прикрепление, общие шаблоны и связи импорта.
- `tests/ui/tournament-list-workflow.cjs`: подменённые API без записи в общую БД; выбор между страницами/поисками, ошибка с сохранением ввода, закрытие и обновление после успеха, перенос/открепление конкретной заявки, поля ранга, выбор заявки при смене веса, светлая/тёмная темы и viewport 390 px без горизонтального переполнения страницы.
- Полный PHP-набор: 69 тестов, 608 проверок. Vite production build проходит. Браузерный сценарий проходит в Chrome.

Схема БД и существующие данные этим изменением не мигрируются. Общая рабочая БД в тестах не изменялась. Сквозные проверки остальных разделов scope не считаются закрытыми этими тестами.

<a id="fights"></a>
## Сетки, результаты, неявки и Round Robin

**Где читать и переиспользовать:**
- [app/Services/Tournaments/PoolGenerationService.php](../app/Services/Tournaments/PoolGenerationService.php); [app/Services/Tournaments/BracketService.php](../app/Services/Tournaments/BracketService.php); [app/Services/Tournaments/BracketMutation.php](../app/Services/Tournaments/BracketMutation.php).
- [app/Services/Tournaments/BracketState.php](../app/Services/Tournaments/BracketState.php); [app/Services/Tournaments/BracketTopology.php](../app/Services/Tournaments/BracketTopology.php); [app/Services/Tournaments/BracketSwapService.php](../app/Services/Tournaments/BracketSwapService.php).
- [app/Services/Tournaments/FightResultService.php](../app/Services/Tournaments/FightResultService.php); [app/Services/Tournaments/RoundRobinResultService.php](../app/Services/Tournaments/RoundRobinResultService.php); [resources/js/components/panel/BracketResultModal.vue](../resources/js/components/panel/BracketResultModal.vue).
- [resources/js/components/panel/BracketSwapModal.vue](../resources/js/components/panel/BracketSwapModal.vue).

Реализовано 08.09.2026: FIGHT-01–FIGHT-06. Основание старого поведения: `karaterating/resources/js/components/TournamentBracket.vue`, `karaterating/app/Http/Controllers/TournamentBracketController.php` и генератор пулей. Старые ошибки проверки ID и неполного сброса следующих раундов не сохраняются.

### Доступ и обмен

- Изменение доступно Organization-владельцу и Secretary его организации до конца последнего дня турнира. Балльное ката использует отдельный API таблиц.
- «Переместить участников» открывает модалку выбора двух разных учеников. Обмен разрешён только в обычной сетке без победителей, неявок, счёта и призовых мест. Round Robin исключён.
- Меняются исходные места посева, включая посев сразу во втором раунде. Один бой обновляется одним экземпляром модели; разные бои сохраняются в общей транзакции. Нельзя передавать чужие бои или участника, отсутствующего в исходном составе.

### Результат боя

- Клик по участнику открывает модалку с полными ФИО и строкой его тренера/клуба. Вазари 0..2 и иппон доступны только выбранному победителю. Ввод счёта проигравшему API отклоняет, а не игнорирует.
- Выбор другого победителя сбрасывает прежний счёт и неявки. Выбор неявки снимает победителя и счёт. «Без результата» снимает и результат, и неявки (`absent_ids: []`).
- Победитель и отсутствующие обязаны принадлежать этому бою. Результат нельзя назначить, пока не определены исходы питающих боёв.
- Одна неявка в промежуточном раунде пропускает присутствующего дальше без записанной победы за проведённый бой. Отсутствующий не попадает в бой за третье место. В финале/третьем месте одна неявка назначает присутствующего победителем; две снимают победителя.
- Свободное место посева отличается от ещё не определённого участника. Автопроход допускается лишь после разрешения питающих веток. Пустые заглушки первого раунда не затирают посев второго раунда.

### Зависимости и рейтинг

- `BracketTopology` пересчитывает ветки по раунду/позиции, исключая `3rd` из обычных переходов даже при совпадающих координатах финала и третьего места.
- Изменившийся состав или вновь ожидаемый результат питающего боя сбрасывает зависимого победителя, счёт, неявки и места до конца сетки. Финал и третье место пересчитываются отдельно. Это действует и когда слот остаётся пустым, но двойная неявка отменена и бой снова ожидается.
- Исправление только счёта при неизменном победителе сохраняет последующие корректные бои. Независимая ветка не сбрасывается.
- Рейтинг читает сохранённые бои; отдельного долговременного кэша результата нет. Интеграционный тест проверяет исчезновение очков прежнего чемпиона после отмены его зависимых побед. Профильные медали позднее объединены в StudentMedalService; границы общего рейтинга и оставшаяся сверка указаны в разделе медалей и scope.
- В небольшой сетке третий бой больше не перекрывает полуфиналы; находится слева от финала с тем же вертикальным центром карточки.

### Round Robin

- Результат каждого боя редактируется той же модалкой. Призовые места задаются отдельно.
- Первое и второе места обязательны, третье может быть пустым. Все призёры разные и входят в состав этой категории. Передаётся полный набор её боёв, все они должны иметь тип `Round Robin`.
- Изменение результата/неявки любого боя снимает прежний пьедестал во всех боях категории. После исправления призёры выбираются заново.

### Транзакции и конфликт изменений

- `BracketMutation` блокирует турнир, затем все бои категории в порядке ID. Результат, пересчёт и запись `activity_log` сохраняются одной транзакцией с повтором при deadlock. Перегенерация использует ту же родительскую блокировку.
- GET сетки возвращает `revision`; HTTP-запросы изменения результата, неявок, татами, обмена и призёров обязаны её передать. Устаревшая версия получает HTTP 409 без записи. Модалка сохраняет ввод и предлагает обновить сетку.
- Журнал содержит пользователя, турнир, список, контекст действия, ID всех изменившихся боёв и их old/new, включая зависимые финал и третий бой. Ошибка сохранения боя или журнала откатывает всё.
- Уведомления о боях вызываются после commit. UI получает новое состояние без перезагрузки страницы.

### Проверки

- `tests/Feature/BracketServiceTest.php`: три базовых сценария старого поведения.
- `tests/Feature/FightWorkflowTest.php`: HTTP-права, обмен внутри/между боями, валидация состава/счёта/призёров, раннее исправление после сыгранного финала, неявки и отмена, ожидающие слоты, совпадающие координаты третьего боя, устаревшая версия, rollback боя и журнала, влияние на рейтинг. Проверен действующий генератор на 2/4/5/8/9/16/17/32 участниках; Round Robin отдельно проверен на трёх.
- `tests/ui/fight-workflow.cjs`: модалки, динамическое обновление, конфликт версии, ограничения полей, отмена результата, пьедестал Round Robin, отсутствие перекрытия третьего боя, desktop/mobile и светлая/тёмная темы. API-ответы подставные; общая БД не изменяется.
- Общий PHP-прогон: 97 тестов / 1073 assertions, SQLite in-memory. Реальный параллельный нагрузочный прогон нескольких MySQL-соединений не выполнялся; проверены блокировки в коде и конфликт двух последовательных запросов с одной ревизией.

Миграции для этих изменений не нужны. Рабочие результаты задним числом массово не пересчитывались.

<a id="kata"></a>
## Ката: оценки, финал, результаты и замена видео

**Где читать и переиспользовать:**
- [app/Services/Tournaments/Kata/](../app/Services/Tournaments/Kata/); [app/Http/Controllers/Panel/Tournaments/KataTableController.php](../app/Http/Controllers/Panel/Tournaments/KataTableController.php); [app/Jobs/DeleteUnusedKataVideo.php](../app/Jobs/DeleteUnusedKataVideo.php).
- [resources/js/components/panel/KataScoreCell.vue](../resources/js/components/panel/KataScoreCell.vue).

Реализовано 08.09.2026: KATA-01–KATA-05. Референс поведения: старые `app/Filament/Pages/kata.php`, `app/Http/Controllers/KataController.php`, `resources/js/components/KataTables.vue`. Старые ошибки преобразования строк, потери оценок и сохранения устаревших результатов не переносились.

### Оценки

- Диапазон подтверждён пользователем: **0–10, шаг 0,1**. Принимаются точка и запятая. Пустое поле/`null` снимает оценку. Произвольные строки, отрицательные значения, больше 10, лишние десятичные разряды, массивы и boolean отклоняются с 422.
- Для расчёта нужны все пять корректных оценок. Убирается ровно одна минимальная и одна максимальная оценка, оставшиеся три суммируются в целых десятых. При неполном наборе `total_score`, `min_score`, `max_score` становятся `null`.
- Некорректная старая оценка отображается без округления, чтобы её можно было исправить. Исправление записывает нормализованное значение, даже если float-cast Laravel считает старую и новую строки численно равными. Массового исправления исторических результатов без подтверждения нет.
- Organization/Secretary своей организации редактируют пять колонок в активном турнире. Judge своей организации редактирует только колонку `judge_position`; чужие оценки, сумма, место и призовые флаги в его ответе скрыты. Права проверяются повторно внутри транзакции.

### Конкурентные изменения

- `KataMutation` открывает транзакцию, блокирует турнир, прикреплённый список и его ката-строки в стабильном порядке. Генерация сеток и изменение результатов используют тот же родительский lock турнира.
- API оценки принимает только целевое поле, новое значение и обязательное `original_value`. Сервис берёт свежие оценки под блокировкой и меняет только выбранную колонку. Два сохранения разных колонок не восстанавливают старые значения остальных судей.
- Если выбранная ячейка уже изменена, ответ 409 не перезаписывает её. Если операция сбрасывает финал/результаты, обязательна совпадающая `revision` всей таблицы. Ревизия HMAC не раскрывает скрытые судейские оценки через простой перебор хеша.
- Структурные операции (число финалистов, финал, призёры) всегда проверяют ревизию. Подтверждение не разрешает применить операцию к таблице, изменившейся после показа диалога.
- Изменение оценки, производные значения, удаление/создание финала и activity_log фиксируются атомарно. В журнале есть автор, турнир/список/ячейка, old/new всех затронутых строк.
- Vue последовательно отправляет изменения, сохраняет незавершённый ввод соседних ячеек при обновлении таблицы и оставляет ошибочное значение для исправления. После конфликта доступно явное обновление таблицы. Выбранная вкладка и список остаются в URL.

### Финал и призёры

- Число финалистов: 4–8. Если участников меньше, берутся имеющиеся. Отбор: сумма, затем минимум, затем максимум по убыванию. Неразрешённая ничья на границе отбора останавливает генерацию с 422 без частичной записи.
- Правка предварительной оценки при наличии финала требует подтверждения. После подтверждения удаляются финальные строки и старые места/награды; финал затем создаётся отдельным действием. Смена числа финалистов и повторная генерация также подтверждаются, если финал существует.
- Правка финальной оценки при рассчитанных результатах требует подтверждения и сбрасывает места/призовые флаги финала, сохраняя остальных финалистов и их оценки. Результаты создаются отдельным действием после заполнения всех пяти оценок.
- Призёры сравниваются по сумме/минимуму/максимуму финала, при равенстве по тем же показателям предварительного этапа. Полная ничья, влияющая на первые три места (включая границу третьего и четвёртого), останавливает генерацию вместо случайного выбора по ID.
- Для групп сохраняются `group_id` и `students`; сопоставление предварительного этапа с финалом использует идентичность группы, а не случайного участника.
- Сброс призовых флагов сразу исключает старые награды из расчёта общего рейтинга. Исторический расчёт медалей профиля вынесен в общий StudentMedalService; см. раздел медалей.

### Видео финала

- Web-панель и мобильный API используют `KataFinalVideoService`. Проверяются активность online point-kata, финальная строка, её фактический участник и заявка именно в этом списке. Организатор/секретарь управляют своим турниром; тренер должен быть прикреплён к турниру и владеть учеником.
- Действующий общий сервис принимает MP4/QuickTime/WebM до 102400 КБ (100 MiB) через `KataVideoUpload` и сохраняет его на приватный disk `protected`. Предел одинаков для первого и финального кругов. В транзакции блокируются турнир, строка ката и заявка, обновляются файл/категория и журнал. При ошибке БД новый файл удаляется, предыдущий остаётся.
- Задание `DeleteUnusedKataVideo` для старого файла записывается в `jobs` той же транзакцией через `protected_media_cleanup`. После commit сразу выполняется попытка очистки. При ошибке остаётся долговечное задание с повторными попытками; общие ссылки из других заявок/документов не удаляются. Безопасно очищается также старая публичная копия в `online-kata-videos`.
- Для обработки отложенных повторов нужен worker: `php artisan queue:work protected_media_cleanup`. Соединение использует стандартную таблицу `jobs` и очередь `default`; обычный database-worker той же БД/таблицы/очереди тоже обработает эти задания. Новых миграций нет. При развёртывании обновить config cache и перезапустить долгоживущие workers.
- Перегенерация финала не удаляет видео из заявки: при повторном отборе участника доступен его ранее загруженный ролик. API не возвращает raw path. Выдача остаётся авторизованной согласно [Доступ и защищённые файлы](#security).
- KATA-05 касается замены видео в ката-таблице. Оплачиваемая первоначальная заявка online-ката остаётся отдельным процессом `OnlineKataPaymentService`.

### Проверки

- `tests/Feature/KataWorkflowTest.php`: 19 тестов, 167 assertions. Ввод/очистка, старые некорректные значения, повторные минимумы/максимумы, устаревшие модели/ревизии, подтверждение сброса, число финалистов, ничьи, группы, права, rollback при ошибке журнала, постоянное число запросов чтения, рейтинг, приватная замена видео и компенсация при ошибке БД, общие файлы, мобильный тренер.
- Весь PHP-набор: **116 тестов / 1240 assertions**, SQLite in-memory, без изменений общей рабочей БД.
- `tests/ui/kata-workflow.cjs`: ввод и ошибки, незавершённые соседние ячейки, подтверждение/отмена, финал, ошибка ничьей, повтор загрузки видео после ошибки, сохранение вкладки; desktop 1440 и mobile 390, светлая/тёмная тема. API подменён тестовыми ответами, визуально проверены снимки подтверждения.
- Регрессии браузера: `fight-workflow.cjs`, `tournament-management-workflow.cjs`, `tournament-list-workflow.cjs`. Сборка Vite проходит.
- Не заменено этими тестами: реальный параллельный прогон нескольких соединений MySQL, внешняя доставка/воспроизведение настоящих видео и восстановление отсутствующих локальных файлов (QA-07). Эти проверки остаются в scope.

<a id="medals"></a>
## Медали, общий рейтинг и история ученика

**Где читать и переиспользовать:**
- [app/Services/StudentMedalService.php](../app/Services/StudentMedalService.php); [app/Services/RatingService.php](../app/Services/RatingService.php); [app/Services/Students/StudentCompetitionHistory.php](../app/Services/Students/StudentCompetitionHistory.php).
- [app/Services/Tournaments/PanelTournamentVisibility.php](../app/Services/Tournaments/PanelTournamentVisibility.php); [app/Http/Controllers/Panel/StudentController.php](../app/Http/Controllers/Panel/StudentController.php); [resources/js/pages/panel/StudentDetailPage.vue](../resources/js/pages/panel/StudentDetailPage.vue).

Реализовано 08.09.2026: RATING-01, RATING-02, RATING-04.

Основание: старый `app/Models/User.php::{getMedalsCount,getMedalsCountKata,getEffectiveCompetitiveRecordStartDate}`, страницы WinTable/LossTable; действующие проверки доступа к турнирам v2. Общий расчёт `StudentMedalService` вызывается из web-профиля ученика и мобильных ответов просмотра/сохранения профиля. Копии `kumiteMedals`/`kataMedals` из обоих контроллеров удалены.

### Допуск соревнований

- Кумитэ учитывает только `Tournament::KUMITE`; ката разделено на флажковую (`FLAG_SYSTEM`, таблица `pools`) и балльную (`POINT_SYSTEM`, таблица `kata_pools`) системы. Остаточные записи в таблице другой дисциплины/системы не создают награды.
- Допускаются масштабы `city`, `region`, `federal_district`, `all_russian`, `international`, `russian_championship`. Клубные, межклубные, неизвестные и отсутствующие масштабы исключены. Как в старом профиле, используется список slug, а не переключатель `is_rating`.
- Удалённые турниры, удалённые/отсутствующие чемпионаты исключены.
- Применяется `getEffectiveCompetitiveRecordStartDate`: дата турнира должна быть не раньше начала истории, включая граничный день. Сохранено старое правило: `null` и ещё не наступившая дата начала не ограничивают историю.
- Медали накопительные, без фильтра текущего года или организации зрителя. Позиция/баллы рейтинговой карточки за год остаются отдельным расчётом `RatingService`.

### Подсчёт наград

- В сетке золото выдаётся только участнику с назначенной победой в `final`, серебро его сопернику в завершённом финале, бронза победителю `3rd`. Победитель обязательно является одним из участников этого боя. Незаполненный финал, промежуточные победы и посторонний ID победителя наград не дают.
- Каждая награда сетки считается по `distinct tournament_id`, не по числу списков. Два золотых результата в разных списках одного турнира дают одно золото в этой ветке.
- Round Robin использует назначенные `winner_id_1rd_robbin`/`2rd`/`3rd` только на строках `type = 'Round Robin'` и для участника соответствующего списка. Повторение призёров в каждой строке круговых боёв не умножает награды.
- Сохранена структура старого расчёта: distinct по турниру отдельно для обычной сетки и отдельно для Round Robin, затем суммы складываются. Если один спортсмен действительно награждён в обеих системах одного турнира, обе ветки учитываются, как в старой версии.
- Балльное ката учитывает только финальные строки с призовыми флагами. Участник определяется по `student_id` либо по массиву `students` группового ката. Поддерживаются числовые и строковые ID старых JSON-массивов; совпадение в обоих полях не удваивает одну строку.
- В балльном ката, как в старом расчёте, отдельные личные/групповые категории могут дать несколько наград одного турнира. Считаются финальные призовые строки, а не distinct tournament.
- Подсчёт выполняется тремя агрегатными SQL-запросами, без загрузки всей истории боёв в PHP и без запросов на каждую награду. Новые значения вычисляются при запросе профиля; записи победителей и исторические данные не переписываются, отдельной миграции/массового пересчёта нет.

### История и доступ

- Турниры и победы/поражения из глобальной истории не скрываются только потому, что принадлежат другой организации.
- Web-API добавляет `can_open` к турнирам и к турниру внутри каждой записи истории. Значения вычисляются одним пакетным запросом по правилам `PanelTournamentVisibility`.
- Эта же проверка видимости используется `BaseTournamentController` для чемпионатов и турниров. Для перехода должны быть доступны и чемпионат, и конкретный турнир. Сохранены существующие правила Organization, Secretary, Admin, Coach и Student; права управления не расширены.
- Vue делает строки с `can_open=true` доступными для перехода мышью и Enter. Остальные строки не имеют вида/семантики ссылки, не попадают в клавиатурную навигацию и не отправляют запрос при клике. Обработчик в App также проверяет capability.
- Скрытая ссылка не заменяет серверную авторизацию: прямые API-запросы к чужому турниру продолжают возвращать 403. Принятая заявка другой организации не превращает эту организацию во владельца; её отдельный разрешённый сценарий работы с собственной командой не изменён.

### Проверки

- `tests/Feature/StudentMedalsTest.php`: 13 тестов / 48 assertions. Уникальность турниров, незавершённый/некорректный финал, масштабы, даты, удаления, Round Robin, обе системы ката, группы с JSON ID разных типов, совпадение web/mobile, HTTP-права и ссылки Organization/Secretary, роли Coach/Student/Admin/Judge, постоянное число запросов.
- Полный PHP-набор: 129 тестов / 1288 assertions в SQLite in-memory. Запрос сервиса отдельно выполнен на локальной MySQL в режиме чтения; данные не менялись.
- `tests/ui/student-history-workflow.cjs`: отображение медалей, переходы из турниров и побед, отсутствие переходов/запросов из чужой истории, клавиатура, desktop 1440 и mobile 390, светлая/тёмная тема. Браузер использует тестовые API-ответы. Проверены снимки интерфейса и отсутствие горизонтального overflow страницы.
- Регрессия `tests/ui/tournament-management-workflow.cjs`; сборка Vite и проверка синтаксиса изменённых контроллеров.
- Эти изменения не меняют формулу рейтинговых баллов и не закрывают полную сверку фильтров общего рейтинга, документов и экспортов из QA-06.

<a id="exports"></a>
## Выгрузки, фоновая генерация и частичные обновления

**Где читать и переиспользовать:**
- [app/Services/Exports/](../app/Services/Exports/); [app/Services/Tournaments/TournamentDownloadService.php](../app/Services/Tournaments/TournamentDownloadService.php); [app/Jobs/RunPanelTask.php](../app/Jobs/RunPanelTask.php).
- [app/Console/Commands/CleanPanelTasks.php](../app/Console/Commands/CleanPanelTasks.php); [app/Http/Controllers/Panel/PanelTaskController.php](../app/Http/Controllers/Panel/PanelTaskController.php); [app/Http/Controllers/Panel/Tournaments/TournamentOptionController.php](../app/Http/Controllers/Panel/Tournaments/TournamentOptionController.php).
- [app/Exports/](../app/Exports/); [resources/views/pdf/](../resources/views/pdf/); [resources/js/pages/panel/PanelTaskPage.vue](../resources/js/pages/panel/PanelTaskPage.vue).

Реализовано 09.09.2026: EXPORT-01..04, TECH-01..04.
Старый проект использован как референс поведения, старые исходники и рабочие турнирные данные не изменялись.

### Скачивания

- «Скачать пули» использует `downloads/brackets`, «Скачать таблицы» использует `downloads/kata-tables`. Это самостоятельные пакеты всех сгенерированных категорий, не ссылки на протоколы.
- Протоколы кумитэ и ката остаются отдельными действиями с реквизитами и подписями главного судьи/секретаря. Скачивание одной ката-таблицы сохранено отдельно.
- Результаты, справка, списки PDF/XLSX и ученики тренера остаются доступными по прежним ролевым границам. Новые организаторские скачивания не открываются Coach.
- PDF не использует flex/grid для расположения таблиц и подписей. Геометрия сетки вычисляется в BracketPdfLayout, колонки одинаковой ширины, стадии называются 1/N финала, третье место расположено слева на высоте финала.
- Подписи в пулях задаёт [BracketParticipantLabels](../app/Services/Tournaments/BracketParticipantLabels.php): для `region`, `federal_district`, `all_russian`, `russian_championship`, `international` вместо клуба показывается регион участника (`users.region_id` → `regions.name`); для клубного, межклубного и городского масштаба остаётся клуб тренера. Отсутствующий регион отображается как локализованное «Регион не указан», без подмены клубом или регионом проведения турнира. ФИО тренера в существующей подписи сохранено. Источник региона пока выбран как регион ученика; уточняющий вопрос владельцу о регионе тренера отправлен 17.09.2026, ответа на момент реализации нет.
- Это единое правило для web/mobile сетки (включая оба Flutter-режима), Round Robin/его призёров, PDF пулей и протоколов кумитэ. Совместимые API-поля `coach_line`/mobile `club` в сетке содержат готовую контекстную подпись; глобальный профиль, списки и ката-таблицы не менялись. Масштаб и регионы загружаются пакетно, без запроса на каждого участника. Сохранённые записи пользователей/боёв не изменяются.
- Проверка подписей 17.09.2026: web HTTP для всех восьми масштабов и отсутствующего масштаба, mobile HTTP/Round Robin, PDF обычной сетки/третьего места/Round Robin с длинными именами и регионами, RU/EN-заглушка. Целевые 25 тестов / 227 assertions, полный backend suite 319 тестов / 4280 assertions (SQLite `:memory:`), Pint. Два двухстраничных тестовых PDF отрисованы Poppler и проверены визуально; реальные файлы учеников не использовались. Сборка Flutter/Vue не нужна: изменён серверный текст, контракт и клиентские компоненты прежние.
- Пакет сеток сохраняет размер каждого листа: небольшие сетки A4 landscape, 16 участников A3 portrait, 32 участника A2 portrait. FPDI объединяет страницы без растягивания маленьких категорий до A2. Печать A2 с уменьшением до A4 уменьшит и текст; это осознанный выбор в пользу полного читаемого электронного оригинала.
- В групповой ката выводятся все участники, их тренерские клубы, оценки и итоговый состав призеров. Финал и результаты группы начинаются с отдельных страниц. Длинные ФИО/клубы переносятся, состав не обрезается.
- Доменные данные (ФИО, название клуба/турнира/категории, введенное пользователем) не переводятся. Подписи, сообщения валидации и управления, единицы возраста/веса/кю/дан, подписи рейтинга локализованы RU/EN. Язык браузерного скачивания берется из locale или cookie kr-locale, фиксируется в задании; числовой возраст не приходит русской строкой «лет».
- Участник чемпионата уникален по student_id внутри выбранных тренеров. Количество дисциплин соответствует старой единице подсчета: число разных турниров, а не число строк заявки и не число уникальных текстовых названий. Личная и групповая заявки в одном турнире не удваивают счетчик. Названия дисциплин перечисляются без повторов.
- Варианты тренеров включают реально участвующих в чемпионате тренеров, включая внешнего тренера из FORM-05. Неизвестный/недопустимый выбранный ID дает ошибку, не игнорируется. Архивные ученики/турниры исключаются.
- В командном XLSX больше нет limit(5000). Отфильтрованный набор читается через lazy(500)/генератор. Строки записываются как текст, а не исполняемые формулы. Клуб берется у тренера; одинаковый клуб/тренер группы не повторяется в XLSX, разные не теряются.

### Фоновое выполнение и доступ

`PanelTasks`, `RunPanelTask`, `PanelTaskRunner` и `PanelTaskController` обслуживают PDF/XLSX организатора/секретаря и массовую генерацию сеток.

1. JSON API отвечает 202 с task; обычная ссылка перенаправляет на /panel/tasks/{uuid}.
2. Страница показывает очередь, выполнение, готовность или ошибку, обновляет статус и предоставляет скачивание/возврат. Перезагрузка страницы не теряет задание.
3. Одинаковый незавершенный запрос одного пользователя не ставится повторно. UUID не заменяет авторизацию: статус и файл доступны только инициатору в прежней организации с действующей ролью и доступом к источнику.
4. Рабочий процесс заново проверяет пользователя и права через те же контроллеры. Файлы хранятся на protected disk, не под public/storage. При получении файла права проверяются снова.
5. Срок скачивания 24 часа. Сбой не публикует частичный файл; плановая уборка удаляет просроченные и прерванные файлы. Зависшее выполнение старше 15 минут отмечается ошибкой.
6. Массовая генерация фиксирует ревизию турнира, списков, заявок и результатов. При изменении исходных данных за время ожидания задание завершается ошибкой без стирания новых результатов. Генерация и состояние ready фиксируются одной транзакцией; чтение актуальной ревизии использует блокировки.
7. В activity_log записываются постановка, выполнение, готовность, ошибка, истечение срока и скачивание, инициатор, организация, контекст, old/new. Для single kata PDF есть tournament.kata_table.downloaded с чемпионатом, турниром, списком и источником. Аудит массовой генерации включает старые/новые пули и ката-таблицы.

Очередь отдельная: connection и queue `panel_tasks`. Рабочий процесс не обслуживает платежи/приглашения/default queue. Лимит задания 600 секунд, одна попытка, retry_after 660 секунд, память процесса 512M. Повышение memory_limit до 1024M внутри PDF-запроса удалено.

Это ограничивает память выборки БД, но не делает PhpSpreadsheet бесконечным потоковым форматом: workbook занимает память рабочего процесса. Проверено 5001 отфильтрованное лицо. При превышении ресурсов операция дает ошибку вместо неполного «успешного» файла; произвольные объемы не объявлены нагрузочно проверенными.

### Пагинация и запросы

- Варианты тренеров/списков перенесены на /attach-options/{coaches|lists}: серверный поиск, страницы по 30, организационная и дисциплинарная совместимость. Vue сохраняет выбранные ID между поисками/страницами, отменяет устаревшие запросы и показывает число выбранных.
- История ученика имеет отдельный /history API с kind/page/per_page; начальная страница по 20, максимум 50. Счетчики и возможность пройти все страницы согласованы.
- Detail принимает parts и metadata. После изменения обновляются затронутые части; формы создания и варианты прикрепления всех вкладок не загружаются заново.
- В trainerStudents тренеры загружаются пакетно. KataPdf загружает групповые составы и их тренеров пакетно. Кумитэ/Round Robin используют заранее загруженную карту людей, а не User::find внутри шаблона.
- Полные первые данные вкладок и прежние форматы ответа сохранены там, где пагинация уже была; это не рефакторинг всех контроллеров проекта.

### Проверки

На 09.09.2026:

- Полный backend: **234 теста, 2348 assertions**, SQLite in-memory. Без писем, списаний и записи синтетических участников в общую MySQL.
- PanelExportTest: **10 тестов, 95 assertions**. Реальный XLSX с 5001 строкой, формулы как текст, несколько тренеров/внешняя команда, двойная заявка, возраст/дисциплины, права на чужое задание/файл, отзыв прав, срок и уборка, stale generation, пагинация, EN-валидация и постоянное число запросов при росте состава.
- Сборка Vue успешна. Playwright прошел для panel-export-workflow, student-history-workflow, tournament-management-workflow, kata-workflow, fight-workflow. Проверены обе темы, RU/EN, мобильный viewport, выбор между страницами, обновление без перезагрузки, готовность/ошибка задания. API в браузерных тестах подменен: это проверка UI, не сквозная авторизация на рабочей БД.
- Сгенерированы и просмотрены **12 новых PDF / 57 страниц**: 2/3/4/8/16/32 участника, Round Robin, третье место, длинные имена/клубы, личная/групповая ката, предварительный этап/финал/результаты, протоколы, официальные результаты, справка, списки, EN.
- `verify-export-pdfs.py` проверяет непустые страницы, границы текста, наличие каждого участника в каждой сетке, выравнивание третьего места с финалом, EN-подписи ката. Рендер Poppler просмотрен дополнительно: проверка bounding boxes не заменяет визуальную проверку.
- Для сравнения на тех же синтетических моделях сформированы HTML восьми старых шаблонов и PDF Chromium. Проверялись поля и верстка, а не пиксельная идентичность. Старый общий официальный results formatter не содержит отдельной ветки балльной/групповой ката: пустой старый результат не принят за эталон, новая ветка выводит призеров из FINAL. Это не проверка реальной исторической БД.
- Найденные рендером дефекты исправлены: тесная сетка 32 участников, обрезанный правый столбец списков, лишние листы подписей кумитэ, узкие/разорванные заголовки ката.

Остались общие QA-01..QA-07 из [единый scope](remaining-scope.md), в частности настоящая конкуренция соединений MySQL, реальные исторические рейтинги, отсутствующие медиа и Excel экзаменов. Эти проверки не помечены выполненными данным переносом выгрузок.

### Запуск и повторная проверка

Миграция: `2026_09_09_150000_create_panel_tasks.php`.
На этой локальной установке применена только она; panel-worker запущен, scheduler уже работал. Перезапуск всего Docker не нужен.

Для другой установки после доставки кода и composer install:

```sh
php artisan migrate
php artisan config:clear
docker compose up -d --no-deps panel-worker
docker compose --profile background up -d --no-deps scheduler
```

При изменении кода рабочего процесса перезапустить только panel-worker. Scheduler запускает panel:clean-tasks ежечасно. Вне Docker используйте эквивалентный supervised queue:work panel_tasks --queue=panel_tasks и системный schedule:run.

PDF-фикстуры создаются PanelExportTest с SAVE_EXPORT_QA=1 в storage/framework/testing/export-qa. Старые Blade-шаблоны для необязательного сравнения помещаются в его legacy/ из readonly-референса вместе с trophy.svg; приложение их не использует.
Скрипты tests/ui/render-legacy-exports.cjs, export-contact-sheets.py и verify-export-pdfs.py повторяют Chromium-рендер, PNG-контактные листы и геометрические проверки. Эти файлы содержат только синтетические данные.

<a id="mobile-auth"></a>
## Мобильный вход, сессия и навигация

**Где читать и переиспользовать:**
- [app/Http/Controllers/Mobile/MobileAuthController.php](../app/Http/Controllers/Mobile/MobileAuthController.php); [app/Http/Middleware/MobileTokenAuth.php](../app/Http/Middleware/MobileTokenAuth.php); [app/Http/Middleware/MobileCoachOnly.php](../app/Http/Middleware/MobileCoachOnly.php).
- [Flutter: lib/auth/auth_session.dart](../../karaterating_trainer/lib/auth/auth_session.dart); [Flutter: lib/auth/session_controller.dart](../../karaterating_trainer/lib/auth/session_controller.dart); [Flutter: lib/api/session_http_client.dart](../../karaterating_trainer/lib/api/session_http_client.dart).
- [Flutter: lib/navigation/coach_shell.dart](../../karaterating_trainer/lib/navigation/coach_shell.dart); [Flutter: lib/navigation/coach_navigation.dart](../../karaterating_trainer/lib/navigation/coach_navigation.dart).

Реализовано 08.09.2026: AUTH-01, AUTH-02, AUTH-03, NAV-01 из аудита мобильного тренера.

### Вход и регистрация

- С 14.09.2026 «Зарегистрироваться» выбирает роль Coach/Student и открывает нативный [RegistrationScreen](../../karaterating_trainer/lib/auth/registration_screen.dart), без браузера/WebView. Новая/существующая учётная запись, код организации/тренера, email, пароль, имя/фамилия для нового аккаунта; затем шестизначный код из email. При ошибке ввод сохраняется; возврат к данным позволяет запросить новый код. Challenge и пароль не сохраняются в preferences; после перезапуска регистрацию нужно начать заново.
- Регистрация по общему коду не требует предварительного письма-приглашения: Coach вводит код организации, Student код тренера. Собственный email и его подтверждение остаются обязательными. Общая реализация web/mobile сохраняет ожидающую заявку и после подтверждения привязывает аккаунт; наличие старого приглашения на другой email/в другую организацию не определяет новую привязку. Поведение покрывают `MobileRegistrationTest`, `TeamWorkflowTest` и `MobileStudentsTest`, включая повтор, неверный/отозванный код и отказ в переносе существующего чужого аккаунта.
- JSON API: `POST /api/mobile/auth/registration/{trainer|student}` и `/confirm`, [MobileRegistrationController](../app/Http/Controllers/Mobile/MobileRegistrationController.php). [InvitationRegistrationData](../app/Services/Account/InvitationRegistrationData.php) общая для web/mobile, привязка через прежние `AcceptTrainerInvitation`/`AcceptStudentInvitation`. Роли, приглашение, пароль существующего аккаунта и принадлежность проверяются сервером повторно при подтверждении. Для Coach успешный экран возвращает email в login. Для Student подтверждение возвращает настоящую mobile-сессию с аудитом `mobile.auth.login` (`source=student.registration`), далее стандартный `SessionController`, согласия и первичная анкета. Bearer выдаётся только в JSON с `Cache-Control: no-store`.
- [MobileRegistrationChallenge](../app/Services/Account/MobileRegistrationChallenge.php): случайный 64-символьный токен только в JSON, ключ кэша SHA-256, зашифрованные подготовленные данные без открытого пароля, hash email-кода, TTL 10 минут и максимум 5 попыток. Общий cache store с atomic locks сериализует попытки/потребление; в production нельзя использовать process-local array cache. Успешная привязка одноразовая. HTTP endpoints ограничены throttle, locale приходит через Accept-Language. Browser session/cookies не нужны.
- Письма `trainer-invitation`/`student-invitation` используют общий `mail.partials.mobile-install`, RU/EN `mobile_registration.php`. Переменные `MOBILE_APP_IOS_URL` и `MOBILE_APP_ANDROID_URL` принимают HTTPS-ссылки реальных магазинов или тестового распространения. Ненастроенные/невалидные ссылки не показываются; письмо предлагает запросить доступ у отправителя. Ссылки пока не предоставлены владельцем, это внешняя зависимость в scope. Мобильный список приглашений отдаёт `app_downloads` вместо web registration URL.
- «Забыли пароль» пока открывает `/forgot-password` во внешнем браузере через `ApiClient.recoveryWebUri()`. В URL нет bearer/email/пароля. После сброса пользователь возвращается в приложение и входит новым паролем; browser cookies не заменяют мобильную сессию.
- Форма входа прокручивается при клавиатуре, пароль скрыт, автокоррекция/подсказки для пароля выключены. Новые сообщения переведены RU/EN.

Проверки нативной регистрации 14.09.2026: `MobileRegistrationTest` покрывает Coach/Student, приглашение секретаря в его организацию, отсутствие аккаунта до email-подтверждения, неверный/истёкший/повторный код, отзыв приглашения, существующий аккаунт и запрет присвоения другой роли; письмо RU/EN с настроенными и отсутствующими ссылками. Полный PHP-прогон: **297 тестов / 3916 assertions**, SQLite `:memory:` с пустым DB_URL и отдельным config cache, fake mail/storage. Flutter `registration_test.dart` проверяет ошибки/повтор без потери данных, оба вида регистрации, отсутствие browser URL/bearer в анонимном запросе, локаль и размеры 360/393/430 px в двух темах при масштабе текста 1/1,6/2; `widget_test.dart` проверяет переходы из настоящего app shell. Реальные письма не отправлялись, магазины/доставка на устройство требуют внешней приёмки.

Полный Flutter-прогон этого изменения: **84 теста**, `flutter analyze --no-pub` без замечаний; iOS simulator build собран. Сборка не опубликована в магазинах и не устанавливалась на пользовательское устройство. Старую установленную версию требуется обновить для нативного экрана регистрации.

### Сессия

- `AuthSession` хранит сессию целиком в `flutter_secure_storage` 10.3.1 (lockfile), а не SharedPreferences. Ветка 10.x совместима с Android compile SDK 36 проекта; 11.x требует SDK 37. iOS: Keychain, `unlocked_this_device`, без синхронизации iCloud; Android: защищенное хранилище с ключом Keystore. Настройки платформ соответствуют [документации пакета](https://pub.dev/packages/flutter_secure_storage/versions/10.3.1).
- Android cloud backup и device transfer исключают shared preferences/root; авто-бэкап выключен. iOS использует device-only accessibility и отдельные entitlements.
- Старые `auth_token/auth_until/auth_email/auth_user_name` переносятся при первом запуске. Plaintext удаляется только после успешной записи в защищенное хранилище; при его недоступности приложение не авторизует пользователя и не использует небезопасный fallback.
- Восстановление обязательно проверяет `/api/mobile/auth/user`. Неизвестный/просроченный токен, удаленный пользователь, потеря роли Coach или внешняя учетная запись не открывают приложение. Сетевой сбой при запуске показывает повторную проверку, а не бесконечно ошибающуюся ленту.
- Проверка повторяется при возвращении из фона; локальное истечение срока закрывает сессию. Временный сетевой сбой при возврате из фона сам по себе не отзывает токен.
- «Выйти» находится в меню, с подтверждением. POST logout отзывает только текущий токен и атомарно пишет activity_log. При сетевой ошибке выход не объявляется успешным: можно повторить или отменить. Уже отозванная сессия очищается локально.
- Единая обработка 401 покрывает JSON, скачивания и multipart. Все защищенные маршруты/диалоги удаляются из Navigator. Поздний 401 старого токена не сбрасывает новый вход; 403 отдельного ресурса не равен выходу.
- Пока идет очистка Keychain, новый вход недоступен, чтобы отложенное удаление не стерло новый токен. Bearer не логируется; авторизованные запросы не следуют redirect автоматически.

### Навигация

- Корень приложения: лениво создаваемый IndexedStack «Рейтинг / Лента / Профиль». Состояние ленты сохраняется при переключениях.
- Все основные экраны используют существующий `Scaffold.bottomNavigationBar`. Меню открывает разделы через push, не заменяя корень; нижний пункт из раздела возвращает к нужной вкладке. Назад из деталей/настроек возвращает предыдущий экран.
- Меню Coach: ученики → турниры → быстрые данные → экзамены → обучение; после разделителя соглашения → настройки → «О нас» → выход. Отдельный пункт «Заявки и оплата» убран для Coach и Student по решению владельца; серверный MobileAppAccess и клиентский ApiClient учитывают это, в том числе при старой сохранённой навигации. Оплата онлайн-ката внутри турнира, возврат провайдера и восстановление состояния заявки сохранены.
- Исправлены найденные при регрессии фиксированные высоты фильтров рейтинга и бейджей профиля. Устаревший тест «Профиль -> настройки» заменен проверкой меню. Добавлен отсутствовавший фон темной темы входа из нового веб-проекта.

### Проверки

- `flutter test`: 26 тестов, включая миграцию токена, ошибки хранилища/сети, восстановление, разные виды 401, logout, отсутствие bearer в browser URL, повторные переходы и очистку всех маршрутов.
- `MobileSessionTest`: 4 теста / 24 assertions. Полный Laravel-набор: 133 / 1312, только изолированная SQLite `:memory:`, не общая база.
- iPhone 17 / iOS 26.5: настоящий Keychain write/read/delete с отдельным временным тестовым ключом; экран входа RU/EN, light/dark, клавиатура. Скриншоты: `karaterating_trainer/build/auth-screenshots/`.
- Собраны обычное iOS-приложение для симулятора и Android debug APK. iOS-сборка установлена поверх тестовой без удаления данных приложения.
- `flutter analyze --no-fatal-infos`: без ошибок/предупреждений; остаются 5 ранее существовавших info в турнирных экранах, перечисленных в TEST-02.
- Реальная почта, пользовательские пароли и рабочая база в тестах не менялись. Отправка письма регистрации/сброса проверяется серверными тестами с fake mail; приемка доставки реальному адресату и Android Keystore на физическом устройстве остаются release-проверками.

Нужна полная пересборка/переустановка приложения поверх существующего, не hot reload: добавлен нативный плагин. Данные сохраняются и мигрируют. Для выпуска задавать HTTPS `API_BASE_URL`; локальный `127.0.0.1:8080` предназначен для разработки в iOS-симуляторе. Docker ради этих правок перезапускать не требуется.

<a id="mobile-profile"></a>
## Мобильный профиль, удаление и согласия

**Где читать и переиспользовать:**
- [app/Services/Account/CoachProfileAccess.php](../app/Services/Account/CoachProfileAccess.php); [app/Services/Account/UpdateCoachProfile.php](../app/Services/Account/UpdateCoachProfile.php); [app/Services/Account/ProfileFieldValidation.php](../app/Services/Account/ProfileFieldValidation.php).
- [app/Services/Account/MobileAgreements.php](../app/Services/Account/MobileAgreements.php); [app/Http/Controllers/Mobile/MobileTrainerProfileController.php](../app/Http/Controllers/Mobile/MobileTrainerProfileController.php); [app/Http/Middleware/MobileAgreementConsent.php](../app/Http/Middleware/MobileAgreementConsent.php).
- [Flutter: lib/profile/trainer_profile_screen.dart](../../karaterating_trainer/lib/profile/trainer_profile_screen.dart); [Flutter: lib/account/agreements_screen.dart](../../karaterating_trainer/lib/account/agreements_screen.dart).

Обновлено 08.09.2026. PROFILE-01, PROFILE-02, PROFILE-04, PROFILE-05 реализованы. PROFILE-03 отменён пользователем: документы, номера и экзаменационные реквизиты собственного профиля тренера не добавляются. PROFILE-06 остаётся в [единый scope](remaining-scope.md): конфигурация нативных push не предоставлена, регистрации устройств и доставки пока нет.

### Профиль и права

- GET/POST `/api/mobile/trainer/profile` возвращает `patronymic` и `capabilities`. Явно валидируемый набор полей сохраняется целиком; прежний `fill()` молча пропускал поля, отсутствующие в `$fillable` модели. Отсутствующее в запросе отчество не стирается.
- По решению владельца 17.09.2026 общий `TournamentProfileAccess` заменяет глобальный запрет из старого CanEditTrait: дата рождения, ранг и связанное право удаления ограничиваются только запретами владельцев **активных турниров, где редактируемый пользователь заявлен участником** (`student_tournaments`). Для самостоятельного изменения Student используется `can_edit_students`, для изменения Coach своего профиля/своего ученика — `can_edit_coaches`. Переключатель Secretary меняет настройку его организации, но затрагивает только участников её турниров. Связь тренера с турниром через `tournament_treners` не делает его самого участником.
- Домашняя организация без такой заявки не блокирует данные, включая первоначальную регистрацию/анкету. При участии только в чужом турнире действует настройка его владельца. Если ученик участвует в нескольких турнирах, достаточно одного применимого запрета; разрешение другой организации его не отменяет. Активность до конца календарного дня `date_finish`, как `TournamentLifecycle::active`; удалённые турниры/чемпионаты и завершённые соревнования не блокируют. Открепление последней заявки снимает связанный запрет. Массового изменения пользователей или заявок нет.
- Вес блокируется при личном участии тренера через `student_tournaments` в неудалённом турнире с неудалённым чемпионатом. Связь `tournament_treners` сама по себе вес не блокирует. Операции закрываются после дня `date_finish`, согласованно с действующей логикой v2.
- Остальные обычные поля, в том числе ФИО, email, пол, клуб, город тренировок и рост, не блокируются организационным флагом. Три флага SettingCoach не изменены; применение разрешений к заявкам исправлено в CoachTournamentAccess, см. раздел мобильной записи.
- Flutter блокирует только соответствующие поля и не отправляет их. API повторно проверяет фактические изменения в транзакции с блокировкой пользователя и организаций-владельцев применимых турниров (в порядке ID), не только домашней организации. Неизменённые запрещённые поля допустимы для старых клиентов. Произвольные role_id/organization_id и дополнительные поля не применяются.
- Дата проверяется строго в форматах `Y-m-d`/`d.m.Y`; невозможные и будущие даты отклоняются. Новый ранг: 0–10 кю или 1–10 дан. Неизменённый legacy-ранг не заменяется значением по умолчанию.
- Аватар: ограничение формата, размера и разрешения; новый файл компенсируется при ошибке БД. Старый файл собственного mobile-profile namespace удаляется только после сохранения и проверки отсутствия другой ссылки.
- Изменения пишутся в activity_log: `mobile.trainer.profile.updated`, актор, old/new, `self`.
- Проверка адресности запрета 17.09.2026: `TournamentProfileAccessTest` покрывает переключатель Secretary через HTTP, отсутствие заявки, участие только у другого организатора, несколько организаторов, различие Student/Coach, фактическое личное участие тренера, конец дня, открепление и soft delete. GET capabilities и запрещённый POST согласованы; отказ не меняет пользователя/журнал. Регистрация/заполнение анкеты проверены при обоих выключенных флагах организации с fake mail. Целевые 50 тестов / 582 assertions, полный backend suite 324 теста / 4323 assertions в SQLite `:memory:`, Pint проходит. Это не новый прогон MySQL-конкуренции. Flutter/Vue используют прежние capabilities, пересборка не нужна; рабочие настройки/пользователи/заявки не менялись.

### Удаление

- В редакторе есть отдельное подтверждение с замаскированным паролем, доступное по capability. POST `/api/mobile/account/delete` проверяет пароль, `confirmed`, роль, организационное право и ограничение частоты.
- Пользователь soft-delete, все mobile/personal tokens, серверные sessions и старые push subscriptions отзываются, remember_token очищается, push_enabled выключается. Activity log: `mobile.account.deleted`.
- Ученики, связи и результаты не удаляются каскадом. Список учеников организации и разрешённый просмотр профиля учитывают архивного тренера с прежним organization_id, включая его клуб; чужая организация по-прежнему получает 403. Это не восстанавливает вход удалённого тренера.
- После успеха приложение очищает защищённое хранилище и весь стек экранов через единый механизм завершения сессии. При ошибке пароль/серверного отказа диалог остаётся открытым.

### Согласия

- Используется общий сервис `Agreements` и существующая таблица `agreement_acceptances`, а не отдельная мобильная копия согласий. Обязательные документы: ID 2 (`success_politic`), ID 3 (`data_processing`), как в старом ConsentGate. Версия зависит от названия и содержания.
- Отображаемое название mobile API получает через `Agreements::title` и `lang/{ru,en}/account.php`: `terms_of_service`, `privacy_policy`, `data_processing_consent` превращаются в понятные локализованные названия в списке и просмотре. Пользовательское название документа сохраняется как есть. Перевод не меняет сохранённый `type`, hash версии или ранее принятые согласия. Регрессия 17.09.2026: `MobileAgreementsTest`, 5 тестов / 75 assertions в SQLite `:memory:`, включая смену языка после принятия и неизменность версий; Pint пройден. Flutter пересобирать для изменения подписей не требуется.
- Новые authenticated Coach endpoints: GET `/agreements`, GET `/agreements/{id}`, POST `/agreements/{id}/accept` под `/api/mobile`. Список пагинирован, содержание очищено `SafeContent`, административного CRUD нет.
- Нужны оба legacy-флага и принятые актуальные версии. Повторное принятие идемпотентно; если запись версии есть, а флаг сброшен, он восстанавливается с отдельным аудитом. Устаревшая версия запроса отклоняется. Документы должны быть опубликованы администратором: отсутствие обязательного ID не обходит проверку.
- Остальные рабочие mobile API возвращают 428 `agreements_required` до принятия. Auth/user, logout, документы/accept и защищённое паролем удаление остаются доступны.
- На начальном входе рабочая лента не запрашивается до согласий. При 428 во время работы отдельный блокирующий экран сохраняет нижележащую навигацию и состояние. Системный Back не открывает защищённый экран. После явных checkbox/подтверждений повторно проверяется сессия, затем показывается прежний экран. Неуспешная мутация автоматически не повторяется.
- Соглашения доступны отдельно через меню. Внешние HTTPS-ссылки открываются без bearer; ссылки на документы открываются внутри приложения. Тексты нового интерфейса RU/EN.

### Проверки

- Laravel: 143 tests / 1402 assertions, SQLite `:memory:`, рабочая общая база не изменялась. Новые MobileProfileTest/MobileAgreementsTest проверяют round-trip, запреты и разрешённые поля, дату/ранг, вес и день завершения, очистку аватаров, пароль/право/отзыв токенов, сохранность ученика и межорганизационную границу, версии и флаги согласия, отсутствие CRUD.
- Flutter: widget/session tests проверяют начальные и повторные согласия с возвратом в детали, сохранение отчества и пропуск запрещённых полей, удаление с паролем и очисткой навигации. Отдельно проверяется 428 от предыдущей сессии.
- `integration_test/profile_consent_test.dart`: пройден iPhone 17 / iOS 26.5, профиль и редактор RU/EN, клавиатура, соглашения light/dark, без реальных файлов/мутаций базы. Скриншоты: `karaterating_trainer/build/auth-screenshots/profile-*.png`, `agreement-*.png`. Работает с тестовым API; это не сквозная запись реального аккаунта.
- Анализатор: ошибок/warnings новых файлов нет; остаются пять прежних info в турнирных экранах. Реальная доставка push, проверки физических Android/iOS устройств и общая тёмная тема старых экранов остаются открытыми в scope.

<a id="mobile-students"></a>
## Мобильные ученики, документы и код тренера

**Где читать и переиспользовать:**
- [app/Services/Students/StudentProfileAccess.php](../app/Services/Students/StudentProfileAccess.php); [app/Services/Students/UpdateStudentProfile.php](../app/Services/Students/UpdateStudentProfile.php); [app/Services/Students/StudentDocumentStatus.php](../app/Services/Students/StudentDocumentStatus.php).
- [app/Services/Team/CoachInvitations.php](../app/Services/Team/CoachInvitations.php); [app/Services/Team/AcceptStudentInvitation.php](../app/Services/Team/AcceptStudentInvitation.php); [app/Http/Controllers/Mobile/MobileStudentController.php](../app/Http/Controllers/Mobile/MobileStudentController.php).
- [app/Http/Controllers/Mobile/MobileStudentInvitationController.php](../app/Http/Controllers/Mobile/MobileStudentInvitationController.php); [Flutter: lib/students/](../../karaterating_trainer/lib/students/).

Реализовано 08.09.2026: STUDENT-01–10. Это действующие правила, не список оставшейся работы.

### Приглашение по коду

- У тренера один постоянный уникальный код `coach_join_codes`, создаваемый сервером. В разделе «Ученики» действие приглашения открывает код с копированием, отправку на несколько email и постраничный список ожидающих. Вкладки основного списка учеников не возвращены.
- Письмо содержит код тренера и установку/регистрацию в приложении, без `/student/register`, referral-параметров и bearer (изменено 14.09.2026, см. mobile-auth). Отправка возвращает результат по каждому адресу: очередь, ошибочный адрес, уже свой ученик, недоступный аккаунт, ошибка отправки. Очередь не означает подтверждённую доставку провайдером.
- Ученик вводит код, создаёт аккаунт либо указывает пароль существующего свободного аккаунта Student. Затем подтверждает email шестизначным кодом из письма. Срок подтверждения 10 минут, ограничение попыток и частоты запросов. Код тренера сам по себе не разрешает изменение аккаунта.
- Копирование кода работает без предварительного письма: публичная регистрация создаёт ожидающее приглашение, но не пользователя. Пользователь создаётся/привязывается только после подтверждения email. Чужой привязанный, удалённый, внешний или привилегированный аккаунт автоматически не переносится. Пароль и личные данные существующего ученика сохраняются.
- Привязка, роль, подтверждение приглашения и activity_log выполняются транзакционно с блокировками. Повторное/просроченное подтверждение и удалённое приглашение отклоняются. Ученик после регистрации не авторизуется в панели организатора.
- `target_role` разделяет приглашения тренеров организацией и приглашения учеников тренером. Миграция исправляет классификацию старых приглашений от Coach; приглашения учеников не попадают в организационное «Ожидают» и не принимаются как приглашения тренеров.

### Доступ и редактирование

- Закрытые профиль, документы, история и категории доступны только своему ученику. Тренер той же организации не получает доступ автоматически. Пять файлов выдаются авторизованным маршрутом `ProtectedMedia`, без публичных URL.
- Из участников доступного турнира можно открыть отдельную безопасную карточку чужого ученика: имя, аватар, тренер/клуб, возраст, пол, вес и пояс. API проверяет турнир, чемпионат и фактическое участие; не возвращает email, дату рождения, документы и историю. Редактор скрыт. При отказе/сетевой ошибке показывается ошибка с повтором/возвратом вместо бесконечного индикатора.
- `StudentProfileAccess` возвращает полевые capabilities. При редактировании своего ученика тренером дата рождения/ранг зависят от `can_edit_coaches` владельцев активных турниров этого ученика через `TournamentProfileAccess`; без применимого запрета доступны. Остальные разрешённые поля не блокируются этим флагом. Flutter не отправляет заблокированные поля, backend повторяет проверку под блокировкой. Даты проверяются строго, ранг ограничен допустимыми кю/дан, email уникален. Сохранение прежнего допустимого/исторического ранга не заменяет его первым элементом select.
- Вес, рост, email и разрешённые реквизиты сохраняются явно, без потери полей из-за `$fillable`. Подтверждения документов, включение в проверку и срок подтверждённой страховки нельзя изменить запросом тренера.
- Открепление требует своего ученика и подтверждения. Обнуляется только `coach_id`, аккаунт, турнирные/экзаменационные заявки и история не удаляются. После возврата список обновляется.

### Документы

- `StudentDocumentStatus` общий для мобильного списка/профиля и турнирной проверки. Для турнира страховка должна действовать до его окончания; вне турнира проверяется на сегодня. Неподтверждённые документы, отсутствующий срок и просрочка имеют отдельные причины. IKO/сертификат с выключенным included-check не считаются обязательными.
- Все пять существующих загрузок сохранены. В редакторе доступны просмотр сохранённого файла, замена, подтверждаемое удаление при сохранении и отмена удаления до сохранения. Карточки показывают реальные причины, срок и исключение из проверки.
- Новые файлы сохраняются на `protected`. При отказе/ошибке БД новый файл удаляется. Старый файл удаляется после успешного сохранения, если на него больше нет ссылок документов/аватара, включая soft-deleted пользователей. Общий файл не удаляется.
- Старые публичные документы, в том числе нестандартные пути, переводятся на приватное хранение до удаления ссылки из БД: иначе исчезновение последней ссылки могло бы открыть `/storage/...`. При rollback прежняя запись продолжает читать приватный файл по прежнему пути. Никакие отсутствующие оригиналы не восстанавливаются автоматически.

### Список, история и медали

- Список показывает вес; клуб берётся только от тренера. Действие категорий открывает реальные названия активных списков/дисциплин с пагинацией, а не только счётчик турниров. Поиск ФИО поддерживает отдельные слова; удалённые чемпионаты исключены из активного счётчика.
- `StudentCompetitionHistory` задаёт общий отбор web/mobile: действующие турниры и чемпионаты, допустимые масштабы, спортивный период и результаты кумитэ. История турниров уникальна по турниру, не по заявке; мобильные турниры, победы и поражения доступны постранично по 20 без прежних общих ограничений 20/100.
- Рекорд рассчитан по той же выборке, что страницы боёв. Имена соперников, тренеры/клубы, категории и даты в истории не обрезаются. Чужая соревновательная история не открывает управление турниром; `can_open` вычисляется общей политикой видимости. Мобильный список истории не содержит запрещённых ссылок.
- Медали уже были подключены к `StudentMedalService`: повторный отдельный подсчёт не создан. Сохранены проверки завершённости, дисциплины, масштаба, периода, удалений, Round Robin, флажкового и группового ката, единицы подсчёта из старой логики. См. [Медали, общий рейтинг и история ученика](#medals).

### Проверка и развёртывание

- `MobileStudentsTest`: свои/чужие профили и все пять файлов, полевые ограничения, строгие даты/ранги, email, замена/удаление/rollback, общие и нестандартные файлы, сроки/исключения, открепление, код/письма/ожидающие, подтверждение новым и существующим учеником, запрет переноса и повторного принятия.
- `StudentMedalsTest`: общий web/mobile расчёт, безопасная карточка участника, пагинация 23 уникальных турниров и 115 боёв. Тесты работают на SQLite `:memory:`, не на общей MySQL.
- Flutter `student_workflow_test.dart`: закрытый/турнирный профиль, ограниченный редактор, удаление сохранённого файла, результаты отправки/отмена приглашения, следующие страницы истории. Эти же сценарии пройдены нативно на iPhone 17 с тестовым API; скриншоты в `karaterating_trainer/build/auth-screenshots/student-*.png`.
- Vue production build и iOS simulator build проходят. `/student/register` проверен в браузере: новая/существующая учётная запись, код тренера и скрытый пароль. На симулятор возвращена обычная сборка приложения с сохранением данных.
- Локальная миграция `2026_09_08_120000_add_coach_join_codes.php` применена. На другом окружении развернуть миграцию, backend/Vue и мобильную сборку вместе; прежние кэшированные маршруты при наличии перечитать.
- Реальная доставка приглашений/OTP через Resend и пользовательские документы не проверялись: реальные письма не отправлялись, чужие аккаунты/файлы для тестов не менялись. Это остаётся в общей сквозной приёмке TEST-04. Систематическая проверка увеличенного текста/RU–EN/тем/Android остаётся в UI/I18N/TEST scope.

<a id="mobile-enrollment"></a>
## Мобильный каталог и запись на турнир

**Где читать и переиспользовать:**
- [app/Services/Tournaments/CoachTournamentAccess.php](../app/Services/Tournaments/CoachTournamentAccess.php); [app/Services/Tournaments/CoachTournamentEnrollment.php](../app/Services/Tournaments/CoachTournamentEnrollment.php); [app/Services/Tournaments/TournamentStudentEligibility.php](../app/Services/Tournaments/TournamentStudentEligibility.php).
- [app/Http/Controllers/Mobile/MobileTournamentController.php](../app/Http/Controllers/Mobile/MobileTournamentController.php); [app/Http/Controllers/Mobile/MobileTournamentItemController.php](../app/Http/Controllers/Mobile/MobileTournamentItemController.php); [Flutter: lib/tournaments/tournament_student_picker.dart](../../karaterating_trainer/lib/tournaments/tournament_student_picker.dart).
- [Flutter: lib/tournaments/tournament_detail_screen.dart](../../karaterating_trainer/lib/tournaments/tournament_detail_screen.dart).

Обновлено 08.09.2026. TOUR-01–07, а также запрет обхода оплаты PAY-01 реализованы.

### Доступ

- «Все» остаётся каталогом общих сведений. Составы, тренеры, списки, таблицы и два документа турнира доступны только явно прикреплённому Coach. Принадлежности той же организации недостаточно.
- Список отдаёт `can_open`; недопущенный турнир нельзя открыть в приложении. Все прямые detail/subresource URL повторяют проверку, включая соответствие чемпионата.
- Полные профили и личные документы участников по-прежнему проверяются отдельным own-only доступом. Публичная карточка соперника не открывает документы.
- Каталог и участники исключают удалённых учеников/тренеров; один ученик считается один раз, независимо от количества заявок. Клуб берётся от действующего тренера.

### Запись

`CoachTournamentAccess` задаёт права для capability, options и POST: свой Student, явный допуск тренера, действующая организация с `can_edit_coaches` и открытые сроки записи.

По решению владельца 17.09.2026 обычное личное прикрепление Coach (`canAttach`) **не блокируется существующей сеткой/офлайн-таблицей**, даже с результатами. `StudentTournamentListAssignmentService` добавляет ученика в подходящий исходный список либо fallback, не меняет Pool/KataPool, оценки, победителей и рейтинг. В сетку новый состав попадает только после явной перегенерации организатором. Capability, attach-options и POST используют одно правило. Прежние ограничения `canManage` для открепления, самостоятельной записи Student, платной онлайн-ката и изменения сформированных групп этим решением не расширены; для них наличие генерации остаётся блокировкой.

Запись и открепление разрешены **до начала дня `date`**, не позже точного `date_commission` и `date_finish` в timezone приложения. На границе комиссии равенство допустимо, следующая секунда закрывает операцию. Начало соревнования закрывает запись с 00:00. Это явное ужесточение старой онлайн-ветки, где отсутствовала проверка разрешения организации; требование «после начала нельзя открепить» применяется одинаково к UI/API.
`date_finish` теперь cast datetime: сохранённое время не теряется. Общая активность соревнования в организационной панели по-прежнему до конца дня окончания; активность и открытая запись не одно и то же.

Флаг `coach.can_attach_to_tournaments_for_students` предназначен для самостоятельной записи ученика, не даёт тренеру обходить организационное разрешение.

Обычный POST онлайн-балльной ката отклоняется. Для неё необходимы категория, видео и платёжный сценарий. Платёжный callback дополнительно перепроверяет актуальные права/стадию и пишет `fulfillment_blocked` при конфликте. Долговечное состояние, идемпотентность и возврат в приложение реализованы позднее в разделе онлайн-ката. Автоматического возврата денег нет; конфликты разбираются отдельно. Sandbox-приёмка остаётся в scope.

### Личные и групповые заявки

- Проверяется конкретная личная membership, а не любое участие ученика в турнире. Группа не мешает добавить личную заявку, в том числе начать онлайн-заявку.
- Мобильный тренер меняет личную заявку. Групповые составы остаются управлением организатора. Открепление личной заявки сохраняет группу и общий enrollment; у чисто группового участия кнопку не показываем.
- Неоднозначная личная связь не удаляется автоматически.
- Прикрепление и открепление выполняются с блокировкой турнира и транзакцией. Недоступный ID/ранг или уже заявленный ученик возвращает 422 с индексом выбора; весь выбор откатывается. Нулевой результат не считается успехом.
- Журнал содержит каждую созданную/откреплённую заявку, актёра, старое/новое состояние и турнир.

### Выбор и отображение

Общий `TournamentStudentPicker` используется для обычной и онлайн-заявки: серверный поиск, страницы по 20, debounce, сохранение выбора при поиске/подгрузке, защита от устаревшего ответа, повторной отправки и закрытия во время сохранения. Ошибка оставляет окно и выбор. Успех закрывает окно и обновляет участников; возврат в чемпионат обновляет счётчики.

Показываются полные имена, вес, ранг и клуб тренера. В информации турнира есть даты с временем комиссии/окончания, адрес, регион, масштаб, цена, возраст, татами, ограничения ранга, главный судья/секретарь, положение и заявление. Файлы загружаются с bearer через разрешённый API и открываются системными действиями; токен не передаётся в URL. Поля служебного отчёта не выдаются.

### Распределение

- Возраст считается **на календарный день комиссии** (`date_commission`) общим `TournamentAge::onCommissionDay`. День рождения в день комиссии уже увеличивает возраст; время комиссии и текущая дата на подбор не влияют. Это исправление старого поведения по решению владельца 17.09.2026. Личные и групповые заявки, проверка возраста группы, импорт анкеты, самостоятельная запись и платное прикрепление используют общий assignment-сервис. Возраст в участниках web/mobile, выборе ученика и мобильном составе списка рассчитан так же.
- Только для legacy-турнира без `date_commission` используется фиксированная дата турнира `date`, не сегодняшняя дата. Без обеих дат или корректной даты рождения возраст неизвестен: личный/импортируемый групповой подбор идёт в fallback; ручная проверка возрастной категории группы не проходит. Устаревшее поле `users.age` не заменяет дату рождения.
- Изменение правила или даты комиссии само по себе не перераспределяет сохранённые заявки и не меняет готовые сетки, оценки и результаты. Новый подбор использует актуальную дату комиссии; изменение старого состава остаётся явным действием.
- Дисциплина и подтип обязательны для всех веток: кумитэ, флажковая, личная и групповая балльная ката.
- При нескольких подходящих списках выбирается первый прикреплённый (стабильный порядок ID).
- Возраст/вес включают обе границы; пол null/all допускает оба пола. Вес участвует только в кумитэ.
- Семантика рангов сохранена в `ListRankCriteria`: нисходящий диапазон кю и старая восходящая пороговая ветка различаются; 0 кю означает белый пояс, дан отдельно нормализуется в старший уровень.
- Fallback имеет стабильную идентичность организация + дисциплина/подтип + служебное имя. Создание сериализуется блокировкой организации; повторное назначение не создаёт новую membership.
- Индексы `2026_09_08_130000_index_mobile_tournament_enrollment` добавлены без уникальных ограничений на старые дубликаты.

### Проверки

- `MobileTournamentEnrollmentTest`: 12 тестов / 103 assertions, включая прямые ID, запрет организации, границы времени, сформированные пули/таблицы, страницы, чужие ID, группа + личная, документы, soft delete/dedup/клубы, rollback и онлайн POST без оплаты. Платёжный провайдер подменён, списаний нет.
- Регрессия 17.09.2026: этот набор расширен до 16 тестов / 233 assertions. Прикрепление Coach в сгенерированные кумитэ, флажковую/офлайн-балльную ката проверено до и после результатов: правильный список, немедленная выдача участника, защита от дубликата и неизменность всех строк сетки/таблицы. Сохранены запреты срока, организации, чужого ученика и онлайн-ката. Полный backend suite: 301 тест / 4055 assertions на изолированной SQLite `:memory:`; Pint проходит. Read-only вызов контроллера на локальных турнирах 49/51 подтвердил `can_attach_students=true` для тренера 5 и доступные варианты выбора; рабочие заявки и бои не изменялись.
- `TournamentListWorkflowTest` дополнительно покрывает обе ветки рангов, 0 кю/дан, смешанные шаблоны, перемещение веса и сохранность групп.
- `TournamentAgeTest` проверяет календарные границы, независимость от сегодня/времени комиссии, високосный день рождения, некорректные даты и legacy fallback. `MobileTournamentEnrollmentTest`, `TournamentListWorkflowTest` и `ExternalFormWorkflowTest` проверяют возраст на комиссии через HTTP-прикрепление/выдачу и импорт личного/группового состава. Прогон 17.09.2026: целевые 47 тестов / 486 assertions, полный backend suite 316 тестов / 4240 assertions в изолированной SQLite `:memory:`; Pint проходит. Рабочие заявки не изменялись.
- Flutter: 37 тестов; отдельные сценарии выбора и информации пройдены на iPhone 17 через integration_test. Скриншоты находятся в `karaterating_trainer/build/auth-screenshots/tournament-*.png`.
- Тестовая БД SQLite in-memory, общие данные не менялись. В рабочей локальной БД применена только новая миграция индексов.
- Реальный платёж, воспроизведение видео и нагрузочная гонка нескольких MySQL-соединений не проверялись. Последующие проверки и реализация описаны в разделе онлайн-ката; внешняя sandbox-приёмка и реальные MySQL-гонки остаются открытыми.

<a id="payments-video"></a>
## Онлайн-ката: оплата, возврат и просмотр видео

**Где читать и переиспользовать:**
- [app/Services/Tournaments/OnlineKataPaymentService.php](../app/Services/Tournaments/OnlineKataPaymentService.php); [app/Services/Tournaments/Payments/](../app/Services/Tournaments/Payments/); [app/Services/Tournaments/Kata/KataVideoAccess.php](../app/Services/Tournaments/Kata/KataVideoAccess.php).
- [app/Http/Controllers/PaymentCallbackController.php](../app/Http/Controllers/PaymentCallbackController.php); [app/Http/Controllers/Mobile/MobileKataPaymentController.php](../app/Http/Controllers/Mobile/MobileKataPaymentController.php); [app/Http/Controllers/Mobile/MobileKataVideoController.php](../app/Http/Controllers/Mobile/MobileKataVideoController.php).
- [app/Console/Commands/MaintainKataApplications.php](../app/Console/Commands/MaintainKataApplications.php); [Flutter: lib/tournaments/kata_payment_return_screen.dart](../../karaterating_trainer/lib/tournaments/kata_payment_return_screen.dart); [Flutter: lib/tournaments/kata_video_upload_sheet.dart](../../karaterating_trainer/lib/tournaments/kata_video_upload_sheet.dart).
- [Flutter: lib/media/protected_video_player.dart](../../karaterating_trainer/lib/media/protected_video_player.dart).

Реализация PAY-01–04 / VIDEO-01–04, 08.09.2026. Это действующие правила; оставшаяся приёмка находится в [единый scope](remaining-scope.md).

### Заявка и платёж

- Обычный POST прикрепления не принимает онлайн-балльную ката. Платный сценарий проверяет своего ученика, допуск тренера, разрешение его организации, ранг, стадию и отсутствие созданных таблиц.
- До обращения к ЮKassa сохраняются UUID заявки, ученик/тренер/турнир/категория, приватный файл, сумма в копейках, RUB, shop_id, неизменяемый payload и active_key. Повторный POST сохраняет первую заявку и её видео, удаляет лишнюю загрузку.
- UUID является ключом идемпотентности. При неопределённом ответе повторяется тот же payload с тем же ключом. После 23 часов создание без известного provider_id останавливается со статусом conflict/creation_unknown, а не создаёт второй платёж. У провайдера гарантия ключа составляет 24 часа: [официальное описание](https://yookassa.ru/developers/using-api/interaction-format).
- Callback использует только присланный payment ID для GET у провайдера. Проверяются ID, metadata.applicationId/paymentType, сумма, валюта, recipient.account_id, paid и статус. Тело webhook не подтверждает оплату.
- В одной транзакции с блокировкой турнира и заявки выполняются повторная проверка условий, StudentTournament, распределение в личный список, fulfilled_at и журнал old/new. После открепления состояние становится detached; повторное уведомление не создаёт заявку снова. Групповая связь не теряется.
- Поздняя оплата или несоответствие параметров дают сохранённый conflict. При сбое БД после подтверждения оплаты показывается processing; сверка повторяет прикрепление, не оплату.
- canceled допускает новую заявку только после подтверждённого действия владельца; новый платёж получает новый UUID. Завершённая или конфликтная заявка не разблокируется автоматически.
- Конфликты разбираются организатором/администратором по application_id/payment_id и activity_log. Автоматического возврата денег нет: возврат выполняется отдельно после проверки в кабинете провайдера.
- Старые платежи без доверенной записи online_kata_applications не восстанавливаются из webhook/cache автоматически. Они фиксируются событием online_kata.legacy_payment_review для ручной сверки.

### Мобильный сценарий

- Публичный return URL не требует web-сессии и предлагает переход karaterating://payment/{uuid}. В URL нет bearer, видео или персональных полей.
- Обработчик приложения ждёт входа и согласий, проверяет владельца платежа и возвращает в исходный турнир. Если доступ к турниру отозван, остаётся страница собственного платежа.
- Статусы обновляются при resume и ограниченном polling, включая восстановление после перезапуска. Источник состояния — БД, не суточный cache. Отдельный пункт «Заявки и оплата» удалён из меню Coach/Student по решению владельца 17.09.2026; экран возврата и предметные сценарии оплаты остаются.
- По нажатию на участника ката открываются имя, клуб от тренера, тренер, полная категория и видео нужного круга. Чужому тренеру API не выдаёт ссылку; прямой GET также запрещён.
- Плеер передаёт bearer только на файловый маршрут своего API origin. Выдача поддерживает Range и private/no-store.
- Первый круг проверяется отдельно. Отсутствие финального видео показывается только для финалиста. Общая KataVideoAccess определяет capability и проверку POST: собственный ученик, назначенный тренер, финальный круг и действующий турнир. Это отдельное окно от первоначальной регистрации.
- Загрузка/замена используют одну форму, полный выбор категории, прогресс, отмену и повтор после сетевой ошибки. Старый файл остаётся при ошибке; удаление после успешной замены имеет долговечную очередь.

### Файлы и запуск

- Новые и ожидающие оплаты видео сохраняются только на disk protected. Правила старых файлов и отсутствие локальных оригиналов: [Доступ и защищённые файлы](#security). Отсутствующие пользовательские видео не восстановлены.
- Лимит обоих кругов приложения/API: 100 MiB; MIME: MP4, QuickTime, WebM. PHP upload_max_filesize=100M, post_max_size=110M; nginx client_max_body_size=112m. Превышение отклоняется с понятным сообщением о 100 МБ; настройки применены 09.09.2026.
- kata:maintain-applications сверяет creating/pending. Через 7 дней удаляются видео отменённых заявок и незарегистрированные временные загрузки; проверки ссылок сохраняют действующие и конфликтные файлы.
- Очистка отменённой заявки ставится в protected_media_cleanup транзакционно с обнулением ссылки и журналом. Очередь повторяет неуспешное удаление.
- Применить миграцию 2026_09_08_140000_create_online_kata_applications.php, собрать Vue/Flutter, включить scheduler (docker compose --profile background up -d app scheduler). В deployment без Docker запускать Laravel scheduler каждую минуту.
- Нужны YOOKASSA_SHOP_ID, YOOKASSA_API_KEY, YOOKASSA_ONLINE_KATA_PRICE, YOOKASSA_VAT_CODE и корректный публичный HTTPS APP_URL. Callback: POST /api/payment-callback. Секреты не публиковать в документации/чате.
- Локально миграция и upload-конфигурация применены, scheduler запущен. На момент проверки shop/key не настроены; запросы в реальную ЮKassa не выполнялись.
- Уточнение 17.09.2026: эффективные shop/key уже заполнены, ключ имеет префикс `live_`; APP_URL `http://localhost:8080`. Не считать окружение тестовым магазином и не инициировать пробные списания. Проверены только признаки конфигурации без вывода секретов и без обращения к провайдеру. Для sandbox-приёмки нужны отдельные тестовые реквизиты и публичный HTTPS callback/return. Онлайн-прикрепление по-прежнему запрещено после появления KataPool (как старый `canCoachAttachOnlineKata`); снятие блокировки генерации для обычного Coach attach не распространялось на платную онлайн-ката.

### Проверки

- Laravel: 183 tests / 1754 assertions, SQLite :memory: вместо общей MySQL. Новый OnlineKataPaymentsTest: 15 сценариев, включая повтор POST/callback, timeout, границу ключа, неверные параметры платежа, позднюю оплату, сбой БД, доступ к видео/Range, финальную загрузку и очистку.
- Flutter: 41 тест; iPhone 17 simulator: 4 сценария оплаты/формы/плеера с подменённым API и отдельный тест нативного видеоплеера. Нативный плеер прочитал защищённый поток по тестовому bearer, воспроизведение продвинулось; проверены скриншоты. Персональные видео в тестах не использовались.
- Vue production build и обычная iOS simulator build проходят. Playwright проверил публичный return на ширинах 320/1280: UUID в deep link, изображение логотипа, отсутствие горизонтального overflow.
- flutter analyze: 3 ранее существовавших info (null-aware в карточке турнира, два braces в tournament_enrollment_test), без ошибок/предупреждений компиляции.
- Реальная sandbox-цепочка ЮKassa с доступным callback, Android/физическое устройство и запуск по ссылке из внешнего банковского приложения остаются приёмкой, а не объявляются проверенными.

<a id="spectator"></a>
## Мобильные списки, сетки и быстрые данные

**Где читать и переиспользовать:**
- [app/Services/Tournaments/CoachQuickFights.php](../app/Services/Tournaments/CoachQuickFights.php); [app/Services/Tournaments/SpectatorFightPath.php](../app/Services/Tournaments/SpectatorFightPath.php); [app/Services/Tournaments/SpectatorTatamiQueue.php](../app/Services/Tournaments/SpectatorTatamiQueue.php).
- [app/Http/Controllers/Mobile/MobileTournamentListController.php](../app/Http/Controllers/Mobile/MobileTournamentListController.php); [app/Http/Controllers/Mobile/MobileQuickFightController.php](../app/Http/Controllers/Mobile/MobileQuickFightController.php); [Flutter: lib/tournaments/tournament_lists_screen.dart](../../karaterating_trainer/lib/tournaments/tournament_lists_screen.dart).
- [Flutter: lib/tournaments/tournament_bracket_screen.dart](../../karaterating_trainer/lib/tournaments/tournament_bracket_screen.dart); [Flutter: lib/tournaments/quick_fights_screen.dart](../../karaterating_trainer/lib/tournaments/quick_fights_screen.dart).

Реализовано 08.09.2026: BRACKET-01–04 и EXPORT-01.

### Сверка со старым проектом

- ListsRelationManager: просмотр прикреплённых списков и состава, Excel/PDF списков. Генерация, редактирование, татами и открепление списка принадлежат организатору/секретарю и в мобильный просмотр не перенесены.
- KataPoolsRelationManager: скачивание списков сохранено через меню турнира, не через кнопку в шапке мобильной таблицы.
- PoolsRelationManager: скачивание PDF пулей требует download_puli_tournament. Coach не получил это право; протоколы, справки, отчёты и все участники чемпионата ему не открыты.
- StudentTournamentActiveWithNumberFight и BracketPathService: свои ученики, активные турниры, фильтр турнира, путь, возможные последующие стадии, текущая очередь татами. Старые запросы на каждый бой заменены пакетным чтением для текущей страницы.

### Доступ и данные

Все прямые маршруты списка/состава/сетки/выгрузки требуют допуска тренера в tournament_treners, живого турнира/чемпионата и соответствия вложенных ID. Каталог «Все» не расширяет эти права.

Составы возвращают только спортивные данные, группу и тренера/клуб. Email, документы, пароли и реквизиты не включаются. Удалённые ученики/тренеры исключаются из состава и экспортов. Клуб берётся только от тренера. Открытие чужого участника использует существующий ограниченный турнирный профиль, а не выдаёт доступ к закрытому профилю.

GET lists без generated показывает все прикреплённые списки, в том числе fallback и пустые. generated=1 сохраняет отдельный список уже созданных пулей/таблиц. Оба варианта имеют пагинацию. Составы имеют отдельную пагинацию и поиск; номер группы одинаков на разных страницах.

В ката members содержит весь сохранённый командный состав с тренером и клубом каждого участника. Команда имеет общий набор оценок и место; в результатах отображаются все имена. Для личного онлайн-ката сохраняются прежние приватные права на видео.

Сетка передаёт аватар, вазари, иппон, неявку и назначенного победителя каждой стороны. Названия стадий вычисляются по глубине основной сетки, без боя за третье место. Название стадии закреплено над прокручиваемыми боями; свайп меняет его. Редактор результатов, swap и управляющие capabilities тренеру не выдаются.

С 17.09.2026 общий Flutter-просмотр сетки Coach/Student имеет переключатель «Раунды / Вся сетка». [BracketOverview](../../karaterating_trainer/lib/tournaments/bracket_overview.dart) использует тот же авторизованный JSON, не открывает PDF/организаторские права. Геометрия BracketOverviewLayout строится по round/position, сохраняет пропущенные позиции и связи; третье место располагается слева на высоте финала в свободном центральном промежутке. InteractiveViewer позволяет масштабировать и перемещать всю схему, есть кнопки увеличения/уменьшения/вписывания. Нажатие на бой открывает прежнюю read-only карточку со счётом и неявками. При возврате к раундам сохраняется выбранная стадия. Для Round Robin и балльных ката-таблиц ложное дерево на выбывание не показывается. Имена и клубы переносятся, высота измеряется с системным масштабом текста; используются обе темы.

У Student учебный раздел `works` называется «Ката разбор» / «Kata review», как старый EducationKlassVideoResource; Coach сохраняет «Работы учеников». Обе подписи (каталог и заголовок работ) задаёт AppStrings.educationSection с ownWorks, без изменения доступа, создания и оплаты работ.

Геометрия линий общей сетки: BracketNode задаёт фиксированную высоту заголовка и двух строк, координаты studentLine/opponentLine/output. Подчёркивания участников и правая скобка рисуются тем же CustomPainter, что межраундовые соединения; не дублировать их border-ами виджетов. Выход середины скобки соединяется с первой/второй строкой следующего боя по нечётной/чётной позиции, а не с центром блока боя. Регрессия 17.09.2026 проверяет точное совпадение концов линий и прямые углы для 2–64 участников и разных высот заголовка; 8 затронутых Flutter-тестов прошли, снимки RU/EN, 360/393/430, обе темы и увеличенный текст проверены widget-матрицей (визуально просмотрены RU-снимки обеих тем).

Проверка 17.09.2026: `bracket_overview_test.dart` покрывает геометрию 2/4/8/16/32/64, неполные позиции, отсутствие пересечений, высоту третьего места, zoom/pan/fit, read-only модалку, сохранение раунда и отсутствие дерева для Round Robin/ката. Widget-матрица: 360/393/430 px, RU/EN, обе темы, textScale 1,6; снимки обеих тем просмотрены. `education_test.dart` проверяет подпись Student в каталоге и заголовке, `widget_test.dart` — отсутствие пункта оплаты. Полный Flutter suite: 92 теста; analyzer без замечаний; iOS simulator debug build успешен. MobileSessionTest: 5 тестов / 51 assertion, SQLite `:memory:` с пустым DB_URL и отдельным config-cache путём; Pint проходит. Это тестовые данные, не личная приёмка владельца на физическом телефоне.

### Где находится в приложении

- Меню турнира «…» -> «Списки участников»: все исходные списки и составы. Из состава с уже созданной сеткой можно открыть её иконкой.
- То же меню -> «Списки Excel» / «Списки PDF»: авторизованное получение файла, сохранение и системное «Поделиться». Заголовок пули не содержит кнопок скачивания.
- Нижнее меню -> «Быстрые данные»: поиск своих учеников, фильтр турнира, страницы, татами, путь, текущий/следующий номер. Клик по стадии открывает соответствующую сетку/строку ката. Состояние обновляется при возврате и каждые 30 секунд на активном экране.

Текущий/следующий номер вычисляется из незавершённых записей татами, это не телеметрия запуска поединка. Для обычных пулей сначала выбираются бои с двумя участниками; если их нет, показывается ближайший назначенный номер ожидающего боя. Номер 0 считается заглушкой. Неявки не остаются текущими. Для ката используется отсутствие итогового rank. Завершённая очередь не подменяется последним завершённым выступлением. Возможный путь явно отличается от уже назначенного боя; после поражения дальнейший путь обрывается, кроме доступного третьего места. Round Robin сохраняет остальные бои после поражения.

### Реализация

Backend: MobileTournamentListController, MobileQuickFightController, CoachQuickFights, SpectatorFightPath, SpectatorTatamiQueue; существующий MobileTournamentItemController и общий TournamentDownloadService.

Flutter: tournament_lists_screen.dart, quick_fights_screen.dart, tournament_detail_screen.dart, tournament_bracket_screen.dart и tournament_models.dart.

Скачивание фиксируется в activity_log с тренером, турниром, чемпионатом, форматом и каналом mobile. Общий PDF-шаблон экранирует пользовательский текст, Excel сохраняет строки как текст, а не исполняемые формулы. Секреты не передаются в URL; файлы запрашиваются с существующей сессией API.

### Проверки и границы

- MobileSpectatorTest: 9 тестов, 73 проверки. Пагинация, fallback до генерации, состав/удаление, групповая ката и результаты, счёт/аватары/стадии, личная область быстрых данных, поиск, завершённость, путь/неявки/Round Robin, очередь татами, прямые чужие ID, whitelist выгрузок и журнал.
- Проверены реальные генераторы PDF и XLSX на изолированных фикстурах; PDF отрисован и просмотрен. Проверена защита Excel от формулы в строковом поле.
- Flutter: отдельные widget/integration-сценарии исходного состава, пагинации, быстрого пути, перехода в финал, свайпа в полуфинал и полной группы ката. Маленький viewport 320 px; iPhone 17 simulator, снимки экранов.
- Полный backend suite: 192 теста / 1827 assertions. Flutter suite: 44 теста. Анализатор: только два прежних info в test/tournament_enrollment_test.dart, без ошибок/предупреждений.
- Общая пользовательская база и результаты соревнований тестами не изменялись. Прогон физического Android/iPhone с реальным турниром и системными получателями файлов остаётся частью общей внешней приёмки; эти проверки не подменены симулятором.

<a id="exams"></a>
## Экзамены и участие тренерских учеников

**Где читать и переиспользовать:**
- [app/Http/Controllers/Panel/ExaminationController.php](../app/Http/Controllers/Panel/ExaminationController.php); [app/Http/Controllers/Mobile/MobileExaminationController.php](../app/Http/Controllers/Mobile/MobileExaminationController.php); [app/Services/Examinations/CoachExaminationEnrollment.php](../app/Services/Examinations/CoachExaminationEnrollment.php).
- [app/Exports/ExaminationStudentsExport.php](../app/Exports/ExaminationStudentsExport.php); [Flutter: lib/examinations/examination_detail_screen.dart](../../karaterating_trainer/lib/examinations/examination_detail_screen.dart).

Реализовано 09.09.2026: EXAM-01 и EXAM-02.

### Сверка старой логики

- ExaminationResource: тренер видит экзамены своей организации.
- StudentsRelationManager: просмотр не ограничен собственными учениками; доступны фильтр тренера и соответствующий Excel.
- Прикрепление и открепление Coach разрешено только для своих учеников. Флаг самостоятельной записи ученика не управляет действиями тренера.
- Ограничения турнирной комиссии/генерации сетки не переносятся на экзамены. Старые действия тренера не закрываются автоматически датой экзамена.
- Создание экзамена, смена результатов и организаторские действия тренеру не добавлены.

### Реализация

Backend: MobileExaminationController, CoachExaminationEnrollment, ExaminationStudentsExport.

Attach-options использует серверный поиск по частям имени/фамилии и пагинацию (20 по умолчанию, максимум 50 за запрос). Доступны живые ученики текущего тренера в его организации, ещё не прикреплённые к экзамену. Возвращаются возраст из birthday, вес, ранг и клуб тренера; student.club не используется.

POST проверяет весь набор student_ids, роль Student, принадлежность тренеру и организации. Пустой/повторяющийся/нечисловой набор отклоняется валидацией. Недоступный ID в смешанном наборе отменяет всю операцию с 403, без частичного прикрепления. Если все выбранные уже прикреплены, возвращается 422; смесь существующих и новых возвращает только действительно добавленные ID.

Изменение участия выполняется в транзакции с блокировкой экзамена и записей учеников, повторной проверкой и activity_log: actor, экзамен/организация, выбранные ID и old/new. Повторное открепление не создаёт повторный журнал и не удаляет аккаунт.

Просмотр участников сохраняет текущую границу mobile по организации, но не сужается до coach_id текущего пользователя. Другие тренеры той же организации и их участники доступны для просмотра/фильтра; can_detach разрешает действие только владельцу ученика. Отсутствующая capability на клиенте трактуется как запрет.

Excel использует тот же coach_id и организационную границу, что список. Добавлен необязательный параметр organizationId общего экспортера только для mobile; web-вызовы с двумя параметрами не изменены. Старое отображение 12 колонок, данных инструктора и кю сохранено. Удалённые ученики исключены ORM. Прямые запросы чужого экзамена и чужого фильтра тренера запрещены, включая выгрузку.

Flutter: examination_detail_screen.dart использует существующий TournamentStudentPicker с переопределённым адресом и преобразованием данных экзамена. Выбор хранится независимо от текущей страницы/поиска. Есть подгрузка, debounce, защита от устаревшего ответа, блокировка повторной отправки и вывод ошибки. Окно закрывается только при непустом подтверждённом результате; затем перезагружаются список и счётчик. Ошибка открытия экзамена завершает загрузку и предоставляет повтор. Исправлен Material-контекст фильтра тренеров.

### Проверки

- MobileExaminationsTest: 6 тестов / 82 проверки, SQLite :memory:. Изоляция организаций для всех маршрутов, участники других тренеров, фильтр и XLSX, 26 учеников/вторая страница/поиск, удалённые/чужие ID, недопустимые наборы без частичной записи, повторные операции, журнал и обновление счётчика.
- XLSX реально создан и прочитан через PhpSpreadsheet: состав без фильтра и с другим тренером, исключение постороннего участника, значения текущего/следующего кю.
- Flutter examination_workflow_test.dart: 3 сценария. Сохранение выбора при поиске/пагинации, отказ сервера и пустой результат, закрытие/обновление после успеха, видимость чужих участников без открепления, передача фильтра в Excel, отказ доступа и повтор.
- Те же сценарии прошли в iPhone 17 simulator. Скриншоты: karaterating_trainer/build/auth-screenshots/exam-selection-search.png, exam-selection-error.png, exam-participants.png. Переполнений на этих экранах не обнаружено.
- Полный backend suite: 198 тестов / 1909 assertions. Полный Flutter suite: 47 тестов.
- Общая MySQL-база не изменялась. Native share Excel с реальным получателем и реальные данные экзамена остаются внешней приёмкой; тест клиента проверяет отправку фильтра до системного диалога, а не имитирует фактическую передачу файла другому приложению.

<a id="feed"></a>
## Лента, обсуждения, реакции и медиа

**Где читать и переиспользовать:**
- [app/Services/Feed/](../app/Services/Feed/); [app/Rules/FeedText.php](../app/Rules/FeedText.php); [app/Http/Controllers/Mobile/MobileFeedController.php](../app/Http/Controllers/Mobile/MobileFeedController.php).
- [app/Http/Controllers/Mobile/MobileFeedCommentController.php](../app/Http/Controllers/Mobile/MobileFeedCommentController.php); [app/Http/Controllers/Mobile/MobileFeedSettingsController.php](../app/Http/Controllers/Mobile/MobileFeedSettingsController.php); [app/Jobs/DeleteUnusedFeedMedia.php](../app/Jobs/DeleteUnusedFeedMedia.php).
- [Flutter: lib/feed/](../../karaterating_trainer/lib/feed/).

Реализовано 09.09.2026: FEED-01–09.

### Сверка и границы

Референс: старые `app/Filament/Pages/Feed.php`, `resources/views/filament/pages/feed.blade.php` и `config/mat_phrases.php`.

- Город всегда участвует в выборке, включая `city_id = null`, как в старом Feed. Выбранная организация ограничивает публикации точным совпадением; публикации с null не примешиваются.
- Город и организация сохраняются в аккаунте (`city_id`, `selected_organization`). Выбор организации ленты не меняет организационную принадлежность пользователя и не выдаёт права управления.
- Варианты организаций: живые аккаунты Organization, как в старом селекторе. Города и организации имеют серверный поиск и пагинацию по 20 вариантов.
- Все аудитории сохраняют базовый контекст города/организации: `all`, `students` (авторы-ученики текущего тренера), `coaches` (авторы Coach), `organization` (выбранная организация либо организация тренера). API сохраняет также `mine` для своих публикаций.
- Старые реакции: `love`, `funny`, `like`, `fire`, `sad`. Историческое мобильное значение `heart` нормализуется в `love`, включая счётчики.

### Backend

Контроллеры: `MobileFeedController`, `MobileFeedSettingsController`, `MobileFeedCommentController`. Повторяемая логика вынесена в `FeedAccess`, `FeedReader`, `FeedPosts`, `FeedDiscussion`; модерация в общее правило `FeedText`.

Лента возвращает 10 записей на страницу, максимум 30. Обсуждения загружаются отдельно: 15 корневых комментариев или ответов на страницу, максимум 30. Ответ на ответ относится к корневому комментарию. Публикация содержит общий счётчик обсуждения, а комментарий — счётчик ответов; полный массив обсуждения в публикацию не вкладывается.

Авторы загружаются ограниченным select без email/документов. Реакции считаются агрегированно для страницы, отдельно загружается выбор текущего пользователя. Добавлены индексы города/организации/автора публикаций, ветки комментариев и цели реакции.

Видимость поста проверяется до чтения содержимого и до каждой мутации, включая прямой ID комментария. Недоступный пост возвращает 404 без изменения. Редактировать и удалять видимый чужой пост/комментарий нельзя (403). Транзакции с блокировкой поста сериализуют мутации обсуждения и сохраняют activity_log с актором, объектом, old/new и связью с публикацией.

Одинаковая проверка запрещённых выражений применяется к созданию и редактированию постов, комментариев и ответов. Словарь и регистронезависимый поиск подстроки соответствуют старому Feed; это не отдельная интеллектуальная модерация.

Итоговое состояние текста/вложения валидируется до удаления старого файла. Новый файл компенсирующе удаляется при ошибке сохранения/журнала; старый удаляется только после commit и только если больше не используется. Задание `DeleteUnusedFeedMedia` сохраняется в существующую database-очередь `protected_media_cleanup`, с повторными попытками при сбое хранилища. Существующий worker этой очереди должен работать при развёртывании.

Медиа публикаций остаются публичными, как в старой ленте. Это не хранилище паспортов или закрытых online-kata видео. Их приватная выдача не изменялась. В URL медиа не передаётся bearer-токен.

### Flutter

- Кнопка настроек открывает выбор города/организации, сохраняет на сервере и обновляет ленту. Устаревшие ответы поиска/смены аудитории игнорируются.
- Подгрузка при прокрутке и кнопкой, pull-to-refresh, обновление при возвращении с другого экрана и возобновлении приложения. Ошибка показывает повтор, а не демонстрационные публикации.
- Собственные публикации, комментарии и ответы можно редактировать/удалять; удаление подтверждается. Пять реакций доступны на постах и комментариях. Счётчики и новые комментарии обновляются сразу, даже если ещё не дочитана история обсуждения.
- Повторные мутации блокируются до ответа. Ошибка сохраняет черновик; окно редактора закрывается только после успеха. Пустая кнопка share удалена.
- В создании/редактировании публикации используется общий `FeedAttachmentButton`: одна скрепка с выбором фото/видео и системным выбором файла. Отдельной кнопки «Объявление» нет. При переходе из ленты в редактор передаётся уже введённый текст; после успешной публикации исходный черновик очищается.
- Аватар автора загружается из API с fallback. Фото открывается целиком с масштабированием. Видео открывает нативный проигрыватель с перемоткой, звуком и состоянием ошибки; поддержка конкретного кодека зависит от платформы.
- Настройки, реакции, редактор, обсуждения и медиапросмотр выделены в компоненты `lib/feed/`; новые тексты добавлены в RU/EN AppStrings.

### Проверки

- `MobileFeedTest`: 12 тестов / 162 assertions на SQLite `:memory:`. Фильтры/аудитории и сохранение настроек; прямые запрещённые мутации без побочных записей; чужие parent ID; все реакции; редактирование/удаление/счётчики; модерация всех текстовых входов; пустое итоговое состояние; ошибки БД и журнала с компенсацией файлов.
- Обсуждение из 171 комментария, включая 140 ответов: проверены поздние страницы, отсутствие вложенного обсуждения в feed payload, ограниченный размер ответа и рост количества запросов при увеличении страницы без N+1.
- `test/feed_workflow_test.dart`: 6 сценариев. Реальная смена контента аудитории, устаревший ответ, пагинация, сохранение настроек, комментарии/ответы/редактирование/реакции/удаление, появление новых записей до последней страницы, сохранение черновика после ошибки, компактный EN/dark экран и полноразмерное фото.
- Те же 6 сценариев прошли через `integration_test/feed_workflow_test.dart` на iPhone 17 simulator. Отдельный `feed_native_video_test.dart` проверил декодированные кадры, воспроизведение и перемотку MP4 из локального тестового media-server; не только наличие значка видео.
- Скриншоты: `karaterating_trainer/build/auth-screenshots/feed-*.png`. На проверенных экранах переполнений нет.
- Полный backend suite: 210 тестов / 2071 assertions. Полный Flutter suite: 53 теста. Pint проверяемых файлов проходит. Analyzer: без ошибок/предупреждений; остаются два уникальных старых style-info в `test/tournament_enrollment_test.dart:45/156` (в текущем выводе продублированы).
- В общей Docker MySQL применена только миграция `2026_09_09_010000_add_mobile_feed_query_indexes.php`, добавляющая индексы. Пользовательские посты, комментарии и вложения тестами не создавались и не менялись. Docker перезапускать не требуется.

### Внешняя приёмка

Тесты UI используют подменённый API, backend проверяется отдельно на изолированной БД. Реальный сценарий публикации с телефона под аккаунтом тренера, Android-устройство, ограничения выбора медиа/кодеков и нестабильная мобильная сеть остаются общей сквозной приёмкой TEST-04. Проверка нативного видео на iPhone simulator не заменяет эту приёмку.

<a id="notices-rating"></a>
## Мобильные уведомления и фильтры рейтинга

**Где читать и переиспользовать:**
- [app/Services/Account/NotificationContent.php](../app/Services/Account/NotificationContent.php); [app/Http/Controllers/Mobile/MobileNotificationController.php](../app/Http/Controllers/Mobile/MobileNotificationController.php); [app/Http/Controllers/Mobile/MobileRatingController.php](../app/Http/Controllers/Mobile/MobileRatingController.php).
- [Flutter: lib/notifications/](../../karaterating_trainer/lib/notifications/); [Flutter: lib/rating/rating_screen.dart](../../karaterating_trainer/lib/rating/rating_screen.dart).

Реализовано 09.09.2026: NOTICE-01–02, RATING-01–03. Обучение реализовано отдельным разделом ниже; нативная доставка push остаётся открытой.

### Уведомления

Референс: старый `app/Filament/Pages/UserAlert.php`. Сохранён индивидуальный `user_alert_user.read_at` и доступ только получателю. Старое автоматическое чтение всех записей при открытии намеренно не переносится: по актуальному требованию пользователь отмечает сообщение или все сообщения явно.

- История: серверная пагинация, 20 по умолчанию, максимум 50; стабильная сортировка по дате и ID. Flutter подгружает страницы по прокрутке или кнопкой, поддерживает обновление и повтор после ошибки без потери уже загруженных строк.
- `GET /api/mobile/notifications/unread` возвращает реальное число непрочитанных текущего пользователя. Общий `NotificationButton` подключён в ленте, рейтинге, учениках, экзаменах, чемпионатах и деталях чемпионата. Статические точки удалены. Счётчик обновляется при открытии/возврате, возобновлении приложения и успешной отметке; запоздалый запрос не перезаписывает свежую отметку. Неизвестный счётчик при сетевой ошибке не выдаётся за ноль.
- Индивидуальная и массовая отметки транзакционны, сериализованы блокировкой пользователя и идемпотентны. Повтор не меняет дату чтения и не дублирует журнал. Проверка получателя происходит до изменения. Activity log содержит актор, объект, старый null и новую дату, количество изменённых записей.
- Клиент меняет строку и счётчик только после успешного POST. Ошибка остаётся ошибкой, видна пользователю; повторные отправки блокируются. Чтение не перезагружает первую страницу и не сбрасывает место в истории.

`NotificationContent` структурно разбирает DOM после общего `SafeContent`, возвращает текстовые фрагменты с безопасными href. Декодируются стандартные HTML entities, сохраняются переносы, подписи ссылок и текст списков/таблиц. Скрипты, обработчики событий, изображения с активными атрибутами и опасные схемы не возвращаются. Для старых ссылок соглашений используется существующее соответствие `/panel/agreement-doc/{id} -> /panel/documents/{id}`.

Flutter отображает компактное сообщение и действующие текстовые ссылки, повторно проверяет схему/адрес перед открытием. Ссылки открываются во внешнем браузере без bearer в URL или заголовках; защищённая web-страница сама требует браузерную сессию и проверяет права. Это не обход авторизации и не автоматический вход в браузере.

Вкладки, «важное», относительное время, декоративная иконка и красная подсветка уведомления не возвращены. Автоматический дублирующий заголовок из первых слов сообщения убран.

### Рейтинг

Референс: старый `RatingPage`, его фильтры и общий канонический `RatingService`.

- Локальные демонстрационные спортсмены, клубы, тренеры и баллы полностью удалены из экрана. Ошибка показывает состояние ошибки и повтор, пустой ответ — пустое состояние. При смене фильтров старые результаты не выдаются за новые.
- Работает явный фильтр организации из вариантов API. Значение передаётся в запрос; без выбора рейтинг остаётся глобальным. Подтверждено тестом на результатах двух разных организаций, а не только наличием параметра.
- Общий сервис возвращает нормализованные фильтры. Ката очищает вес и P4P, P4P очищает вес; недопустимый вес вне вариантов выбранного возраста/пола сбрасывается. Flutter сбрасывает зависимый вес при смене возраста/пола/режима и применяет нормализованный ответ. Запоздалый ответ предыдущего фильтра/дисциплины игнорируется.
- Фильтры остаются подписанными, с иконками: две колонки и полная ширина последнего поля организации. Высота определяется содержимым; длинные значения переносятся, подписи не скрываются, горизонтальный скролл не добавлен.
- Компактный заголовок, корректный контраст фильтров и карточек в тёмной теме. Неработавшая пустая кнопка поиска рейтинга убрана.
- Расчёт спортивных баллов не заменён мобильной копией: продолжает использоваться общий RatingService. Подписи данных/категорий используют локаль API; цепочка сохранения языка и Accept-Language проверена 09.09.2026, см. раздел остаточного scope выше.

### Проверки

- `MobileNotificationsTest`: 6 тестов. 45 сообщений/три страницы, стабильный порядок, граница получателя, отдельные счётчики, идемпотентность одной/массовой отметки, сохранение даты, audit old/new и rollback при ошибке журнала; HTML entities, переносы, безопасные и запрещённые ссылки.
- `RatingServiceTest`: дополнены мобильная цепочка организации с реальными результатами двух организаций и нормализация весов/ката/P4P. Совместный прогон этих двух файлов: 10 тестов / 66 assertions.
- `test/notification_rating_test.dart`: 6 сценариев. Пагинация/ошибка страницы/повтор, отказ отметки без ложного чтения, обновление общего badge после возврата, безопасный адрес ссылки и передача в открывающий обработчик, ошибка рейтинга без mock-данных, рабочая организация, зависимые фильтры и устаревший ответ.
- Проверены 360/393/430 px, RU/EN, длинное название организации, масштаб текста 1.35. Те же сценарии запущены в iPhone 17 simulator.
- Детерминированные снимки Flutter-рендера: `karaterating_trainer/build/auth-screenshots/notifications-history.png`, `rating-error.png`, `rating-filters-ru.png`, `rating-filters-en.png`. Снимки создаёт host widget-тест с `SAVE_SCREENSHOTS=true` и необязательным `SCREENSHOT_FONT`; физический iPhone этим не имитируется.
- Полный backend suite: 217 тестов / 2130 assertions, SQLite `:memory:`. Полный Flutter suite: 59 тестов. Анализатор не сообщает ошибок/предупреждений; остаются два старых style-info в `test/tournament_enrollment_test.dart:45/156`.
- Общая MySQL-база, реальные уведомления и их отметки не менялись. Миграций для этого этапа нет; существующий индекс `user_alert_read_status` используется счётчиком. Docker перезапускать не требуется.

### Оставшаяся приёмка

Тесты backend изолированы; UI использует тестовый API. На физическом устройстве остаются реальный переход во внешний браузер и возврат, системное увеличение текста/шрифт платформы и медленная сеть в общей TEST-04. Проверка передачи безопасного URI в тестовый обработчик не равнозначна проверке браузерной сессии пользователя. Push PROFILE-06 остаётся в scope; EDU-01–02 реализованы в разделе обучения, исходные учебные медиа восстановлены в S3.

<a id="education"></a>
## Обучение и оплаченные работы учеников

**Где читать и переиспользовать:**
- [app/Services/Education/EducationAccess.php](../app/Services/Education/EducationAccess.php); [app/Services/Education/EducationCatalog.php](../app/Services/Education/EducationCatalog.php); [app/Services/Education/CoachEducationWorks.php](../app/Services/Education/CoachEducationWorks.php).
- [app/Http/Controllers/Mobile/MobileEducationController.php](../app/Http/Controllers/Mobile/MobileEducationController.php); [Flutter: lib/education/](../../karaterating_trainer/lib/education/).

EDU-01–02 реализованы 09.09.2026. Раздел находится в нижнем меню «Обучение». Только просмотр; редактор каталога, создание/оплата работ ученика и оценивание мастером тренеру не предоставляются.

### Сверка со старой версией

- EducationKataCategoryResource: категории type=kata_attestation, собственные videos. KihonCategoryResource и IdoGeikoCategoryResource используют ту же модель с type=kihon/ido_geiko.
- KataCompetitionsResource: отдельные kata_competitions и kata_competitions_videos.
- Права старых model policies: view_any_education::kata::category + view_education::kata::category; view_any_kata::competitions + view_kata::competitions; view_any_education::klass::video + view_education::klass::video.
- В локальной БД у Coach эти шесть прав уже есть; также есть update_education::klass::video, но одного permission недостаточно для переноса action: старый Coach не являлся автором-Student или оценивающим Master. Мобильные write endpoints не создавались.
- Старый EducationKlassVideoResource для Coach отбирает is_payment=true и student.coach_id=current_user. Description, point, detail_point и recommendation показываются после is_review.

### Реализация

EducationAccess проверяет Coach и оба ресурсных права с учётом role_id, model_has_roles, прямых model_has_permissions и web guard. Каталог разделов показывает только разрешённые пункты. Прямые запросы категорий, видео, обложек, работ и файлов заново проверяют доступ.

EducationCatalog разделяет категории и видео по реальному типу; ID из другого типа не открывается. CoachEducationWorks выбирает оплаченные работы своих действующих Student; исключает удалённых учеников, откреплённых и чужих. Клуб только от тренера. Список выбирает ограниченные поля и eager-load связей; персональные email/документы не возвращаются. Непроверенная работа не содержит review даже в JSON.

Маршруты, все GET и под mobile.auth/mobile.coach/consent:
- /api/mobile/education
- /api/mobile/education/catalog/{section}
- /api/mobile/education/catalog/{section}/{category}
- /api/mobile/education/works
- /api/mobile/education/works/{work}
- /api/mobile/files/education/catalog/{section}/{video}/{video|poster}
- /api/mobile/files/education/works/{work}

Категории, видео и работы: по 20 записей, стабильная сортировка по ID, поиск и пагинация. Flutter подгружает при прокрутке либо кнопкой, обрабатывает ошибки/повтор и отбрасывает устаревшие ответы поиска. После возврата обновляет данные. Видео имеет play/pause, звук, перемотку, повтор после ошибки и паузу при уходе приложения в фон. ProtectedVideoPlayer общий с онлайн-ката; bearer передаётся только в заголовке файлового маршрута своего API origin.

Панель управления вынесена в [VideoPlayerSurface](../../karaterating_trainer/lib/media/video_player_surface.dart), общий для ProtectedVideoPlayer и FeedVideo. Кнопка разворачивает видео на отдельный чёрный экран без повторного создания контроллера: позиция, звук, воспроизведение и авторизация сохраняются. Выход кнопкой/назад возвращает к исходному экрану; горизонтальные и вертикальные ролики вписываются без обрезки. Управление расположено поверх нижнего градиента, inline-высота ограничена 360 px. Native-проверка 17.09.2026 `education_native_video_test.dart` прошла на iPhone 17 Pro simulator: авторизованный тестовый MP4, воспроизведение, seek, разворачивание и возврат с прежней позицией; это не проверка всех пользовательских кодеков/физического устройства.

Регрессия 17.09.2026: `video_surface_test.dart` проверяет оба соотношения сторон, RU/EN, обе темы, 360/393/430 px и landscape с увеличенным текстом; `kata_payment_workflow_test.dart` сохраняет авторизованные заголовки при fullscreen. `feed_workflow_test.dart` проверяет единую скрепку, выбор обоих типов и перенос черновика; `widget_test.dart` — отсутствие платежей Coach и порядок меню. Полный Flutter suite: 86 тестов, analyzer без замечаний. Backend MobileSessionTest + MobileStaffTest: 16 тестов / 214 assertions на SQLite `:memory:`, Pint проходит. Обычная debug-сборка установлена поверх тестовой в iPhone-симулятор, без удаления данных.

UI: заголовки 16 px, строки 14/12 px, полная категория и имена переносятся в содержимом; обложка фиксированного размера с нейтральным placeholder/error. Статус работы текстом и значком; проверенная работа показывает комментарий, оба балла и рекомендацию. Все новые подписи RU/EN в AppStrings. Данные названий/комментариев сохраняют язык автора.

### Файлы и индексы

ProtectedMedia выдаёт private/no-store Range-ответы и поддерживает старые локальные файлы public disk только через авторизованный обработчик. Прямой /storage запрещён по ссылкам всех трёх таблиц (включая нестандартные пути) и целиком для video/kata-klass, videos/education-kata. В API нет raw path. Команда media:privatize учитывает эти таблицы и каталоги, сверяет SHA-256 перед удалением публичной копии; её --apply на этом этапе не запускали.

Миграция 2026_09_09_120000_index_mobile_education.php добавляет индексы section/ID, student/payment/ID и ссылок path/poster_path. Применена локально; записи пользователей, оплат и оценок не менялись. Сценарий полностью read-only, новых мутаций для activity_log нет; существующая команда физического переноса ведёт аудит. Docker перезапускать не требуется.

**Оригиналы восстановлены в S3 09.09.2026:** первоначальная локальная проверка дала 24 ссылки path и 0 видео, но после копирования со старого сервера все ссылки трёх учебных таблиц, включая обложки, доступны через защищённые обработчики. Итоговые числа и проверка содержимого в разделе S3. Тестовые ролики не подставлялись вместо пользовательских работ. Отсутствующий файл по-прежнему даёт 404 и понятное состояние плеера.

### Проверки

- MobileEducationTest: 7 HTTP-тестов / 122 assertions. Ресурсные права и их отзыв, role/direct permissions, все четыре типа каталогов, принадлежность категории, чужие/неоплаченные/удалённые ученики, скрытые результаты до проверки, сохранённые баллы, отсутствие write API, защищённые video/poster/legacy URL, Range 206, пагинация и постоянное число запросов при разном количестве строк.
- Полный backend suite: 224 теста / 2252 assertions, SQLite :memory:, без тестовых мутаций MySQL.
- test/education_test.dart: 5 сценариев UI, включая путь из меню, allowed sections, player headers, результаты/скрытие черновика, ошибки и повтор страницы, устаревший поиск, RU/EN, 360/393/430 px, textScale=1.35 и обе темы. Те же сценарии прошли в iPhone 17 simulator.
- Полный Flutter suite: 64 теста. Новые файлы без замечаний анализатора; остаются два прежних style-info в test/tournament_enrollment_test.dart.
- integration_test/education_native_video_test.dart: реальный iOS player, авторизованный MP4 Range-поток, движение позиции и seek, без ошибок декодирования. Сервер test_driver/education_media_server.cjs работает только на loopback с тестовым bearer, не обращается к персональным данным.
- Снимки build/auth-screenshots/education-sections.png, education-catalog.png, education-reviewed-work.png, education-works-en.png: детерминированный Flutter render с тестовым API. education-native-video.png: нативный кадр симулятора с тестовым MP4, не пользовательское видео.
- Физический Android/iPhone, воспроизведение восстановленных S3-файлов и нестабильная сеть остаются в общей TEST-04. Получать оригиналы учебного медиаконтента повторно не требуется.
