<?php
/**
 * lib/ethos_content.php — THE single source of truth for the Afrovanguard
 * ethos: vision, mission, the nine commitments, the leadership model, the
 * seven core values, and the creed.
 *
 * The Ethos page renders directly from this. The About page is kept in sync
 * by tools/build-chrome.php, which injects the vision / mission / values from
 * here into about.html. Edit ONLY here, then run: php tools/build-chrome.php
 */
return [
    'preamble' => 'Afrovanguard is a values-driven civic, cultural, leadership, and human-development movement committed to advancing human flourishing, ethical leadership, cultural dignity, accountable governance, sustainable development, and collective prosperity.',

    'quote' => 'Afrovanguard envisions a world where communities are empowered, institutions are accountable, cultures are respected, opportunities are accessible — and leadership is exercised as a sacred responsibility rather than a privilege.',

    'vision' => 'To raise a generation of ethical, competent, culturally grounded, and socially responsible leaders who become a transformative force for good in every sphere of human endeavour.',

    'mission' => 'To cultivate individuals and institutions that advance justice, accountability, cultural dignity, sustainable prosperity, community development, and responsible leadership — through education, service, innovation, advocacy, and ethical action.',

    'commitments' => [
        ['Human Dignity & the Common Good', 'We exist for the good of humanity.', 'Afrovanguard affirms the inherent worth of every human being — societies flourish when individual well-being is bound to the well-being of communities. Genuine development is measured not by economic growth alone, but by human flourishing, social cohesion, justice, and quality of life.', ['Human-centered development','Equal opportunity','Intergenerational responsibility','Social inclusion','Community empowerment','Protection of the vulnerable'], 'For an Afrovanguard, success is meaningful only when it advances others.'],
        ['Selfless Service & Social Impact', 'We are radically selfless.', 'Afrovanguard promotes servant leadership as the highest expression of influence: leadership is a responsibility to serve, not an opportunity to dominate. Members dedicate their knowledge, resources, influence, skills, and networks to solving societal challenges.', ['Service precedes status','Contribution over consumption','Impact outweighs recognition','Legacy over popularity'], 'Service is not charity alone but a strategic investment in human development and social progress.'],
        ['Sustainable Community Prosperity', 'We prosper communities.', 'Prosperity is measured not by wealth accumulation but by the capacity of communities to thrive economically, socially, culturally, and environmentally.', ['Economic empowerment','Educational advancement','Community resilience','Sustainable livelihoods','Entrepreneurship development','Skills & youth empowerment','Innovation ecosystems'], 'We support models that create long-term value over short-term gains, and reject systems that enrich a few while impoverishing the majority.'],
        ['Integrity, Accountability & Anti-Corruption', 'We wage war against corruption.', 'Corruption is among the greatest barriers to development, social trust, and institutional effectiveness — so Afrovanguard maintains zero tolerance for it in all forms.', ['Financial misconduct','Abuse of authority','Nepotism','Bribery & fraud','Misappropriation','Manipulation of systems','Exploitation of trust','Ethical negligence'], 'Every member upholds transparency, accountable governance, and fiduciary responsibility — trained to identify, challenge, and dismantle corrupt practices wherever they arise.'],
        ['Cultural Dignity & Heritage Preservation', 'We protect our divine heritage.', 'Culture is a strategic asset for development and identity. Every civilization holds unique knowledge systems, traditions, and innovations — so we promote, protect, document, and revitalize:', ['Indigenous languages','Cultural arts','Community institutions','Traditional knowledge','Indigenous medicine','Traditional technologies','Local food systems','Historical narratives','Dignifying practices'], 'We reject cultural erasure and inferiority complexes, while embracing innovation that keeps cultures relevant — participating in the world through a confident cultural identity.'],
        ['Responsible Citizenship & Good Governance', 'We build just and responsible systems.', 'An Afrovanguard does not merely criticize broken systems — they actively participate in building better ones, grounded in the rule of law, transparency, equity, and justice.', ['Lawful conduct','Community engagement','Democratic participation','Public accountability','Policy advocacy','Ethical leadership'], 'Good governance is everyone’s responsibility, not the burden of a few.'],
        ['Peacebuilding & Social Cohesion', 'We are ambassadors of excellence.', 'Peace is built upon justice, inclusion, dialogue, and mutual respect.', ['Community mediators','Bridge-builders','Consensus facilitators','Peace advocates','Agents of reconciliation'], 'We reject violence, tribalism, and every form of division — working so that diversity becomes a source of strength rather than conflict.'],
        ['Innovation, Knowledge & Future Readiness', 'We renew through knowledge.', 'Afrovanguard embraces knowledge as a catalyst for transformation — encouraging lifelong learning, research, creativity, and innovation.', ['Educational excellence','Scientific advancement','Digital transformation','Research & development','Technology-driven solutions','Future-focused leadership'], 'Societies that invest in knowledge create sustainable pathways for prosperity and resilience.'],
        ['Environmental Stewardship', 'We are stewards of the earth.', 'Humanity bears responsibility to protect and preserve the natural environment.', ['Environmental sustainability','Climate resilience','Conservation','Responsible resource use','Sustainable agriculture','Ecological restoration'], 'Stewardship of the earth is both a moral and a civic responsibility.'],
    ],

    'leadership' => [
        ['Moral Courage', 'The willingness to defend truth and justice despite opposition.'],
        ['Ethical Competence', 'The ability to make responsible decisions rooted in values and principles.'],
        ['Cultural Intelligence', 'The ability to understand, respect, and engage diverse cultural realities.'],
        ['Social Responsibility', 'The commitment to contribute positively to society.'],
        ['Transformational Influence', 'The ability to inspire positive change in individuals and institutions.'],
        ['Stewardship', 'The responsible management of people, resources, opportunities, and trust.'],
    ],

    'values' => [
        ['Individuation', 'The pursuit of self-discovery, self-mastery, and purpose fulfilment.'],
        ['Faith', 'Confidence in God, truth, possibility, and the power of righteous action.'],
        ['Diligence', 'A consistent commitment to excellence, discipline, and productivity.'],
        ['Accountability', 'Ownership of actions, responsibilities, decisions, and outcomes.'],
        ['Responsibility', 'An active commitment to solving problems and advancing society.'],
        ['Cultural Appreciation', 'Respecting, preserving, and advancing humanity’s diverse heritage.'],
        ['Communal Spirit', 'Promoting cooperation, solidarity, collective prosperity, and cohesion.'],
    ],

    'creed' => [
        'I am an African model.',
        'I am a selfless one.',
        'I generate new ideas and solutions for my community.',
        'I defend and project Africa’s culture through my land, language, and lifestyle.',
        'I am a Force for Good and the model of our values.',
        'I help people achieve their goals daily.',
        'I put my environment into order.',
        'I take responsibility for every complacent, irresponsible, and uncultured one in my space.',
    ],
];
