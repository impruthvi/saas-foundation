export type Project = {
    id: number;
    name: string;
    createdAt: string | null;
};

/**
 * What the projects screen renders, resolved on the server. `limit` and
 * `remaining` are null when the plan is unlimited and 0 when it allows none.
 */
export type ProjectAllowance = {
    limit: number | null;
    usage: number;
    remaining: number | null;
    upgradePlan: string | null;
};
