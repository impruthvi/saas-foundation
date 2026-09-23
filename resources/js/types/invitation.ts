export type OrganizationMember = {
    id: number;
    userId: number;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    status: string;
    isOwner: boolean;
    isYou: boolean;
    canManage: boolean;
};

export type PendingInvitation = {
    id: number;
    email: string;
    role: string;
    expiresAt: string;
    invitedBy: string | null;
};
