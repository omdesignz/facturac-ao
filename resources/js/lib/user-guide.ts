export interface GuideChapter {
    id: string;
    category: string;
    title: string;
    summary: string;
    url: string;
    minutes: number;
    steps: { title: string; body: string }[];
    checklist: string[];
    tip: string;
    question: string;
    answer: string;
    related: string[];
    scenarios?: {
        code: string;
        title: string;
        when: string;
        example: string;
        action: string;
        attention: string;
    }[];
    source?: { label: string; url: string };
    screenshots?: {
        src: string;
        title: string;
        alt: string;
        caption: string;
        instruction: string;
    }[];
}

export interface GuideProgress {
    completed: string[];
    saved: string[];
    checks: string[];
}

export function normaliseGuideText(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLocaleLowerCase('pt')
        .replace(/factur/g, 'fatur');
}

export function matchesGuideSearch(
    chapter: GuideChapter,
    query: string,
): boolean {
    const text = normaliseGuideText(
        [
            chapter.title,
            chapter.summary,
            chapter.category,
            chapter.tip,
            chapter.question,
            chapter.answer,
            ...chapter.checklist,
            ...(chapter.scenarios ?? []).flatMap((scenario) =>
                Object.values(scenario),
            ),
            ...(chapter.screenshots ?? []).flatMap((screenshot) => [
                screenshot.title,
                screenshot.caption,
                screenshot.instruction,
            ]),
            ...chapter.steps.flatMap((step) => [step.title, step.body]),
        ].join(' '),
    );

    return normaliseGuideText(query)
        .trim()
        .split(/\s+/)
        .every((word) => text.includes(word));
}

export function readGuideProgress(
    value: string | null,
    chapters: GuideChapter[],
): GuideProgress {
    const empty: GuideProgress = { completed: [], saved: [], checks: [] };

    if (!value) {
        return empty;
    }

    try {
        const parsed: unknown = JSON.parse(value);

        if (!parsed || typeof parsed !== 'object') {
            return empty;
        }

        const allowed = new Set(chapters.map((chapter) => chapter.id));
        const allowedChecks = new Set(
            chapters.flatMap((chapter) =>
                chapter.checklist.map((_, index) => `${chapter.id}:${index}`),
            ),
        );
        const record = parsed as Record<string, unknown>;
        const filter = (items: unknown, keys: Set<string>): string[] =>
            Array.isArray(items)
                ? [
                      ...new Set(
                          items.filter(
                              (item): item is string =>
                                  typeof item === 'string' && keys.has(item),
                          ),
                      ),
                  ]
                : [];

        return {
            completed: filter(record.completed, allowed),
            saved: filter(record.saved, allowed),
            checks: filter(record.checks, allowedChecks),
        };
    } catch {
        return empty;
    }
}
