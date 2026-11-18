/** Money fields are exact decimal strings in USD. */
export type ReportTotals = {
    generations: number;
    charged: string;
    provider_cost: string;
    profit: string;
    tokens: Record<string, number>;
};

export type ReportUser = {
    id: number | null;
    name: string;
    email: string | null;
};

export type UserTotals = ReportTotals & { user: ReportUser };

/** What GET /v1/usage reported for the same slice, and how it compares. */
export type PixelforgeComparison = {
    generations: number;
    cost: string;
    difference: string;
    matches: boolean;
    tokens_match: boolean;
} | null;

export type ReportDay = ReportTotals & {
    date: string;
    users: UserTotals[];
    pixelforge: PixelforgeComparison;
};

export type AdminReport = {
    start: string;
    end: string;
    days: ReportDay[];
    totals: ReportTotals & { pixelforge: PixelforgeComparison };
    users: UserTotals[];
    pixelforgeError: string | null;
};
