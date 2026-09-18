<?php

return array_replace_recursive(require __DIR__.'/../ru/about.php', [
    'project' => [
        'title' => 'About Karaterating',
        'lead' => 'Karaterating is an information and organization platform supporting karate in Russia and the CIS.',
        'description' => 'We connect athletes, coaches and organizations for training, competitions, personal sports rankings and communication.',
        'goal' => 'Our goal is to bring technology to karate. Enthusiasts and martial arts professionals support the project, with a focus on youth sport.',
        'features' => ['Training', 'Competitions', 'Personal sports rankings', 'Communication'],
    ],
    'company' => [
        'name' => 'Self-employed E. V. Tkodyan',
        'address' => 'Apartment 209, 68/118v Sokolova Avenue, Rostov-on-Don',
    ],
    'bank' => ['bank' => 'VTB Bank (PJSC), Branch No. 2351 in Krasnodar'],
    'contacts' => ['work_time' => 'Mon-Fri, 10:00-18:00 (Moscow time)'],
]);
