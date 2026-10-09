<?php
/**
 * lib/MentorAcademy.php — what a mentor has to know before they are given a
 * young person's trust, and the questions that check they know it.
 *
 * The content is here, in one place, rather than in the views: it is the part
 * most likely to need correcting after a real incident, and a safeguarding
 * rule that has to be found across four templates is a safeguarding rule that
 * gets corrected in three of them.
 *
 * Each module is lessons → one practice scenario → a quiz. The scenario has no
 * score; it exists because the quiz can only ask what somebody knows, and the
 * scenario asks what they would do. The pass mark is 80% (MentorPortal::PASS_MARK).
 */
declare(strict_types=1);

final class MentorAcademy
{
    /**
     * @return array<string, array{title:string, lead:string, lessons:array, scenario:array, quiz:array}>
     */
    public static function all(): array
    {
        return [
            'safeguarding' => [
                'title' => 'Safeguarding young people',
                'lead'  => 'What to do when something worries you, and what never to do.',
                'lessons' => [
                    ['h' => 'You are not the investigator',
                     'p' => 'If a young person tells you something that worries you, your job is to listen, write down what they said in their own words as soon as you can, and pass it to the safeguarding lead the same day. You do not question them further, you do not test whether it is true, and you do not talk to anybody it is about. Asking a second question can make a child repeat a story until it changes, and a changed story is one nobody can act on.'],
                    ['h' => 'Never promise secrecy',
                     'p' => 'If a young person asks you to keep something to yourself, say plainly that you cannot promise that, and that you will only tell the people whose job it is to help. Say it before they tell you if you can. A promise you have to break is worse than a boundary you set honestly.'],
                    ['h' => 'Stay where you can be seen',
                     'p' => 'Meet in the portal, in a programme space, or somewhere public. Do not meet a mentee alone off-site, do not give lifts alone, and do not move the conversation to WhatsApp, direct messages or a personal number. The portal keeps a record, and a record protects the young person and you.'],
                    ['h' => 'Immediate danger comes first',
                     'p' => 'If somebody is in immediate danger, call 112 first. Then tell the safeguarding lead. The report can be written afterwards; the call cannot.'],
                ],
                'scenario' => [
                    'q' => 'Your mentee mentions, almost in passing, that their uncle “gets angry” and that they sometimes sleep at a friend’s house to avoid him. Then they change the subject.',
                    'options' => [
                        ['t' => 'Let them change the subject, write down what they said, and report it to the safeguarding lead today.', 'best' => true,
                         'why' => 'They told you as much as they wanted to. Pressing would make them regret saying anything, and the lead — not you — decides what happens next.'],
                        ['t' => 'Ask what the uncle does when he gets angry, so your report has detail.', 'best' => false,
                         'why' => 'Detail gathered by questioning can make a child’s account unusable, and it is not yours to gather. Report what was actually said.'],
                        ['t' => 'Tell them they can always call you, and give them your mobile number.', 'best' => false,
                         'why' => 'It feels kind and it removes the young person from every protection the programme has. Contact stays in the portal; the lead arranges real support.'],
                        ['t' => 'Wait to see whether they bring it up again next session.', 'best' => false,
                         'why' => 'Waiting is a decision, and it is not yours to make. Same day, to the lead.'],
                    ],
                ],
                'quiz' => [
                    ['q' => 'A mentee asks you to promise not to tell anyone. What do you say?',
                     'a' => ['I promise.', 'I cannot promise that — I only tell the people whose job it is to help.', 'I promise unless it is serious.'], 'correct' => 1],
                    ['q' => 'When do you report something that worries you?',
                     'a' => ['The same day.', 'At the next monthly review.', 'Once you are sure it is true.'], 'correct' => 0],
                    ['q' => 'A mentee asks to meet at a café near their house, just the two of you.',
                     'a' => ['Go — it is a public place.', 'Keep it in the portal or a programme space, and say why.', 'Go, but tell the coordinator afterwards.'], 'correct' => 1],
                    ['q' => 'Somebody is in immediate danger. What comes first?',
                     'a' => ['Write the report.', 'Call 112, then tell the safeguarding lead.', 'Tell the mentee’s parents.'], 'correct' => 1],
                    ['q' => 'After a disclosure, how much should you ask?',
                     'a' => ['Enough to be sure.', 'Nothing further — record their words and pass it on.', 'Whatever the form asks for.'], 'correct' => 1],
                ],
            ],

            'standard' => [
                'title' => 'The Afrovanguard mentorship standard',
                'lead'  => 'What a mentor here commits to, and how it is measured.',
                'lessons' => [
                    ['h' => 'Five points, and all five are checked',
                     'p' => 'Screening before matching. Training before accepting. A written goal both of you agreed. A session rhythm you keep. A planned ending. The portal measures the last three — sessions kept, goals set, how a pairing closed — and the coordinator reads them.'],
                    ['h' => 'Consistency beats intensity',
                     'p' => 'A mentor who turns up every fortnight for a year changes more than one who gives a brilliant hour and disappears. Sessions kept is the number the programme watches, not hours logged, because a kept session is the promise and the hour is only its length.'],
                    ['h' => 'Write the goal down',
                     'p' => 'A goal that lives in a conversation is a goal neither of you can be held to. Agree it, write it in the case file, and your mentee sees it in their own portal. It can change — write the change down too.'],
                    ['h' => 'Log what happened, not what you meant to do',
                     'p' => 'The outcome box is for what was covered and what they will do next. Someone reading it in six months — a coordinator, the next mentor, the young person — should be able to tell what actually happened.'],
                ],
                'scenario' => [
                    'q' => 'You and your mentee have met four times. Every session has been good, but you have never written a goal down, and they have started cancelling.',
                    'options' => [
                        ['t' => 'Use the next session to agree one written goal and the rhythm you will both keep.', 'best' => true,
                         'why' => 'Cancellations usually follow a pairing with no agreed purpose. The goal is the thing that makes the next session worth keeping.'],
                        ['t' => 'Ask the coordinator to re-match them with somebody they will turn up for.', 'best' => false,
                         'why' => 'Re-matching a young person because the pairing had no structure teaches them that adults leave. Fix the structure first.'],
                        ['t' => 'Keep meeting and see whether it settles.', 'best' => false,
                         'why' => 'Four good sessions with nothing written down is exactly the pairing that quietly stops at eight.'],
                        ['t' => 'Log the cancellations as missed and let the numbers tell the coordinator.', 'best' => false,
                         'why' => 'Log them, yes — but the numbers are not a substitute for the conversation you need to have.'],
                    ],
                ],
                'quiz' => [
                    ['q' => 'Which number does the programme watch most closely?',
                     'a' => ['Hours logged.', 'Sessions kept.', 'Messages sent.'], 'correct' => 1],
                    ['q' => 'Where does an agreed goal live?',
                     'a' => ['In the case file, where the mentee can see it.', 'In your notes.', 'In the first session only.'], 'correct' => 0],
                    ['q' => 'What belongs in the outcome box?',
                     'a' => ['How the session felt.', 'What was covered and what they will do next.', 'Your plan for next time.'], 'correct' => 1],
                    ['q' => 'A goal you agreed in March no longer fits in July.',
                     'a' => ['Leave it — it is the record.', 'Agree a new one and write that down too.', 'Delete it.'], 'correct' => 1],
                    ['q' => 'When is screening done?',
                     'a' => ['Before matching.', 'After the first session.', 'Only for staff.'], 'correct' => 0],
                ],
            ],

            'goals' => [
                'title' => 'Goal setting that holds',
                'lead'  => 'How to agree something a sixteen-year-old can actually act on.',
                'lessons' => [
                    ['h' => 'Theirs, not yours',
                     'p' => 'A goal you chose is a goal they will perform for you and abandon when you are not watching. Ask what they want to be true in six months, and work backwards from their answer, even when you can see a better one.'],
                    ['h' => 'Small enough to do this week',
                     'p' => '“Be more confident” cannot be done. “Ask one question in class this week” can, and twenty of those become the first thing. End every session with one action small enough that failing it is informative rather than crushing.'],
                    ['h' => 'Name the obstacle out loud',
                     'p' => 'Ask what would stop them. A goal with the obstacle named has a plan; a goal without one has an excuse waiting. This is the single question that most changes whether a goal survives the month.'],
                    ['h' => 'Review it where they can see it',
                     'p' => 'Open the written goal at the start of the review session and read it together. A goal reviewed out loud is a goal that keeps its grip.'],
                ],
                'scenario' => [
                    'q' => 'Your mentee says they want to “do better in school”. They are vague about what that means and look uncomfortable being asked.',
                    'options' => [
                        ['t' => 'Ask what a good week at school would look like, hour by hour, and write down the first thing they name.', 'best' => true,
                         'why' => 'Describing a week is concrete where “do better” is a judgement. It also moves the conversation off their failure and onto a picture they can build.'],
                        ['t' => 'Set a target grade with them and work back from it.', 'best' => false,
                         'why' => 'A grade is an outcome they only partly control, and it adds the pressure that made them uncomfortable in the first place.'],
                        ['t' => 'Suggest a goal yourself to get things moving.', 'best' => false,
                         'why' => 'It moves faster and it is now your goal. They will report on it, not pursue it.'],
                        ['t' => 'Leave goals until they are more comfortable.', 'best' => false,
                         'why' => 'Comfort comes from having something specific to work on, not from waiting.'],
                    ],
                ],
                'quiz' => [
                    ['q' => 'Whose goal should it be?',
                     'a' => ['Theirs.', 'Yours, if you can see further.', 'The programme’s.'], 'correct' => 0],
                    ['q' => 'Which is a usable action?',
                     'a' => ['Be more confident.', 'Ask one question in class this week.', 'Work harder.'], 'correct' => 1],
                    ['q' => 'What question most improves a goal’s chances?',
                     'a' => ['“What would stop you?”', '“Are you sure?”', '“By when?”'], 'correct' => 0],
                    ['q' => 'How should a goal be reviewed?',
                     'a' => ['Read it together at the start of the review session.', 'Mentioned if it comes up.', 'Checked by the coordinator.'], 'correct' => 0],
                    ['q' => 'They miss the week’s action.',
                     'a' => ['Make the next one smaller and ask what got in the way.', 'Repeat it with a firmer deadline.', 'Move to a different goal.'], 'correct' => 0],
                ],
            ],

            'endings' => [
                'title' => 'Planned endings',
                'lead'  => 'How a pairing finishes without feeling like being dropped.',
                'lessons' => [
                    ['h' => 'An ending is part of the work',
                     'p' => 'Most young people in a mentoring programme have been left by an adult before. An ending that is named, dated and prepared for is one of the most useful things mentoring can give; an ending that just happens repeats the thing it was meant to answer.'],
                    ['h' => 'Say it three sessions out',
                     'p' => 'Name the last session well before it arrives, so there is time to look back rather than only to say goodbye. Surprise endings are experienced as rejection however kindly they are worded.'],
                    ['h' => 'Look back at the goals, together',
                     'p' => 'Open what you wrote at the start and read it with them. What moved, what did not, what they did themselves. This is the part they keep.'],
                    ['h' => 'Agree what happens afterwards, honestly',
                     'p' => 'Say what contact there will and will not be, and keep to it. “Message me any time” that you do not answer is worse than “we stop here, and the programme stays open to you”.'],
                ],
                'scenario' => [
                    'q' => 'You are moving cities in six weeks and will not be able to continue. Your mentee has been doing well.',
                    'options' => [
                        ['t' => 'Tell them at the next session, set the closing date, and plan the last three sessions around looking back.', 'best' => true,
                         'why' => 'Six weeks is enough time to make the ending part of the work instead of an interruption to it.'],
                        ['t' => 'Wait until nearer the time so it does not spoil the sessions you have left.', 'best' => false,
                         'why' => 'It spoils them anyway, retrospectively, and removes the time they needed to prepare.'],
                        ['t' => 'Tell the coordinator and let them handle it.', 'best' => false,
                         'why' => 'The coordinator arranges what comes next. The ending itself is yours to say, in person.'],
                        ['t' => 'Offer to keep mentoring informally by message.', 'best' => false,
                         'why' => 'It is outside the programme’s protections, it rarely lasts, and it prevents a proper handover.'],
                    ],
                ],
                'quiz' => [
                    ['q' => 'When should a planned close be named?',
                     'a' => ['At least three sessions out.', 'In the last session.', 'After the last session, by message.'], 'correct' => 0],
                    ['q' => 'What belongs in the closing session?',
                     'a' => ['Looking back at the written goals together.', 'A gift.', 'A new goal.'], 'correct' => 0],
                    ['q' => 'What do you say about staying in touch?',
                     'a' => ['Whatever is kindest in the moment.', 'Exactly what will and will not happen, and then keep to it.', 'Nothing — let it fade.'], 'correct' => 1],
                    ['q' => 'Why do endings matter so much here?',
                     'a' => ['The paperwork requires it.', 'Many mentees have been left by an adult before.', 'It frees up capacity.'], 'correct' => 1],
                    ['q' => 'You have to stop suddenly and cannot give notice.',
                     'a' => ['Tell the coordinator at once so a handover and an explanation reach the mentee.', 'Stop replying.', 'Ask another mentor to take over quietly.'], 'correct' => 0],
                ],
            ],

            'difficult' => [
                'title' => 'Difficult conversations',
                'lead'  => 'Staying useful when the conversation gets hard.',
                'lessons' => [
                    ['h' => 'Silence is a tool',
                     'p' => 'Count to five before filling a pause. Most of what a young person finds hard to say arrives in the second half of a silence an adult did not interrupt.'],
                    ['h' => 'Describe, do not judge',
                     'p' => '“You have missed the last three” is a fact you can both look at. “You are not taking this seriously” is a verdict, and the only available replies are agreement or defence.'],
                    ['h' => 'You can disagree and stay',
                     'p' => 'A mentor who never disagrees is pleasant and useless. Say the harder thing once, plainly, and then stay in the conversation rather than pressing it.'],
                ],
                'scenario' => [
                    'q' => 'Your mentee tells you, with some heat, that the programme is a waste of time and nobody there actually cares.',
                    'options' => [
                        ['t' => 'Ask what made them feel that, and listen to the whole answer before saying anything.', 'best' => true,
                         'why' => 'It is usually about one specific thing that happened. Defending the programme first guarantees you never hear what it was.'],
                        ['t' => 'Explain what the programme does for them.', 'best' => false,
                         'why' => 'It answers an argument they were not having and tells them this is not a place to be honest.'],
                        ['t' => 'Agree, to keep them on side.', 'best' => false,
                         'why' => 'Agreeing with something you do not believe costs you the one thing that makes disagreement useful later.'],
                        ['t' => 'Report it to the coordinator as a complaint.', 'best' => false,
                         'why' => 'Perhaps, eventually — but first it is a conversation, and treating it as a case closes it.'],
                    ],
                ],
                'quiz' => [
                    ['q' => 'A pause has gone on for four seconds.',
                     'a' => ['Fill it.', 'Wait.', 'Change the subject.'], 'correct' => 1],
                    ['q' => 'Which is describing rather than judging?',
                     'a' => ['“You have missed the last three.”', '“You are not taking this seriously.”', '“You always do this.”'], 'correct' => 0],
                    ['q' => 'You disagree with something they have decided.',
                     'a' => ['Say it once, plainly, and stay in the conversation.', 'Say nothing.', 'Repeat it until they change their mind.'], 'correct' => 0],
                ],
            ],

            'cultures' => [
                'title' => 'Mentoring across cultures',
                'lead'  => 'Difference in the room, named rather than ignored.',
                'lessons' => [
                    ['h' => 'Ask rather than assume',
                     'p' => 'Language at home, who makes decisions in the family, what a given festival means, what is rude to ask — these differ, and asking is respectful where guessing is not.'],
                    ['h' => 'Family is often the decision-maker',
                     'p' => 'A plan a young person agrees to alone may not be theirs to agree to. Ask early who else needs to be comfortable with it, and build that in rather than discovering it later.'],
                    ['h' => 'Your normal is not neutral',
                     'p' => 'Eye contact, disagreement with elders, how much you say about yourself — what reads as confidence in one home reads as disrespect in another. Notice what you are reading as a problem before you name it as one.'],
                ],
                'scenario' => [
                    'q' => 'Your mentee avoids eye contact with you throughout. You read it as disengagement and are tempted to say so.',
                    'options' => [
                        ['t' => 'Say nothing about it, keep going, and look at what they actually do between sessions.', 'best' => true,
                         'why' => 'In many homes, not meeting an older person’s eyes is respect. Their actions will tell you about engagement; their eyes will not.'],
                        ['t' => 'Tell them eye contact matters in interviews and practise it.', 'best' => false,
                         'why' => 'It may be worth teaching one day, as a skill for a specific setting. It is not a verdict on this conversation, and it is not where to start.'],
                        ['t' => 'Ask whether they are bored.', 'best' => false,
                         'why' => 'You would be asking them to account for a sign you misread.'],
                        ['t' => 'Mention it to the coordinator as a concern.', 'best' => false,
                         'why' => 'There is nothing to be concerned about yet.'],
                    ],
                ],
                'quiz' => [
                    ['q' => 'A mentee avoids eye contact with you.',
                     'a' => ['Treat it as disengagement.', 'Treat it as possibly respect, and watch what they do instead.', 'Ask them to look at you.'], 'correct' => 1],
                    ['q' => 'Your mentee agrees a plan that affects their family.',
                     'a' => ['Ask early who else needs to be comfortable with it.', 'Proceed — it is their choice.', 'Ask the coordinator to speak to the family.'], 'correct' => 0],
                    ['q' => 'You do not know what a festival they mention means to them.',
                     'a' => ['Look it up and say nothing.', 'Ask them.', 'Avoid the subject.'], 'correct' => 1],
                ],
            ],
        ];
    }

    public static function module(string $key): ?array
    {
        $all = self::all();
        if (!isset($all[$key])) return null;
        return ['key' => $key] + $all[$key];
    }

    /** Mark a quiz. Returns the percentage and which answers were wrong. */
    public static function mark(string $key, array $answers): array
    {
        $m = self::module($key);
        if (!$m) return ['ok' => false, 'error' => 'Unknown module.'];
        $quiz = $m['quiz']; $n = count($quiz); $right = 0; $wrong = [];
        foreach ($quiz as $i => $q) {
            $given = isset($answers[$i]) ? (int) $answers[$i] : -1;
            if ($given === (int) $q['correct']) $right++; else $wrong[] = $i;
        }
        $score = $n > 0 ? (int) round(100 * $right / $n) : 0;
        return ['ok' => true, 'score' => $score, 'passed' => $score >= MentorPortal::PASS_MARK, 'wrong' => $wrong, 'of' => $n];
    }
}
