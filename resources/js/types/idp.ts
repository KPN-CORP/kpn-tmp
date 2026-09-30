/**
 * The IDP wire contract, shared by the manage screen, the profile tab and the
 * approver's inbox — so a change to what the server ships is a change in ONE
 * place rather than in each screen's own copy of the shape.
 */

export interface MasterOption {
    value: string
    value_en: string | null
    value_id: string | null
    /**
     * The competency type this master is filed under, as the plan stores it (a
     * name string). Null means untyped, which by convention is global and fits
     * every competency type.
     */
    competency_type?: string | null
}

/**
 * A development program additionally carries the development model it is filed
 * under. Null is legacy data with no model, which — like an untyped master —
 * counts as global.
 */
export interface ProgramOption extends MasterOption {
    model_id?: number | null
}

/**
 * One unit of measurement from the `UnitOfMeasurement` catalogue. Both
 * languages travel with it (a plan stores the KEY, not a label) along with the
 * group it belongs to, which the picker shows as its second line.
 */
export interface UomOption {
    value: string
    label_en: string
    label_id: string
    group: string
    group_en: string
    group_id: string
}

export type StepStatus = 'pending' | 'approved' | 'rejected'

export interface ApprovalStep {
    level: number
    approver_id: string
    approver_name: string | null
    status: StepStatus
    note: string | null
    acted_by_name: string | null
    acted_at: string | null
}

export type ApprovalStatus = 'draft' | 'pending' | 'approved' | 'rejected'

export interface ApprovalInfo {
    id: number | null
    stage: 'planning' | 'result' | null
    status: ApprovalStatus
    current_level: number | null
    total_levels: number
    submitted_at: string | null
    steps: ApprovalStep[]
    can_act: boolean
}

/** Where a program's RESULT stands. */
export type ResultStatus = 'locked' | 'open' | 'pending' | 'approved' | 'rejected'

export interface ResultState {
    status: ResultStatus
    /** The realization date + evidence have been filled in. */
    filled: boolean
    approval: ApprovalInfo | null
    /** The chain the result WOULD follow, shown before it is submitted. */
    chain_preview: ApprovalInfo | null
    can_submit: boolean
    can_act: boolean
}

export interface PlanStage {
    planning_approved: boolean
    planning_approved_at: string | null
    can_edit: boolean
    can_delete: boolean
    /** Locked because the package's planning approval is in flight. */
    frozen_by_planning: boolean
    result: ResultState
}

export interface Plan {
    id: number
    development_model_id: number
    competency_type: string
    competency_name: string
    development_program: string
    review_tools: string | null
    expected_outcome: string | null
    /** The quantitative target, paired with its unit: both set, or neither. */
    target: number | null
    /** A `UnitOfMeasurement` backed value (`times`, `percent`, …), not a label. */
    uom: string | null
    time_frame_start: string | null
    time_frame_end: string | null
    realization_date: string | null
    /** What was reached, counted in the plan's own `uom`. A result field. */
    achievement: number | null
    result_evidence: string | null
    stage: PlanStage
}

export interface DevelopmentModelView {
    id: number
    name: string
    percentage: number
    description_en: string | null
    description_id: string | null
    /** Accepts new plans: in the active package AND not frozen by an approval. */
    can_add: boolean
    is_active_package: boolean
    plans: Plan[]
}

/**
 * Where the whole plan set stands:
 *
 *  - draft     nothing submitted yet
 *  - pending   with an approver; the set is frozen
 *  - approved  signed off; results are open
 *  - rejected  declined; revise and submit again
 *  - revision  approved earlier, but rows have changed since
 */
export type PlanningStatus = 'draft' | 'pending' | 'approved' | 'rejected' | 'revision'

export interface PlanningState {
    package: { id: number; name: string; start_date: string | null; end_date: string | null } | null
    status: PlanningStatus
    approval: ApprovalInfo | null
    chain_preview: ApprovalInfo
    /** Earlier submissions of the same set, newest first. */
    history: ApprovalInfo[]
    can_submit: boolean
    plans_editable: boolean
    total_plans: number
    awaiting_plans: number
    has_approvers: boolean
}

export interface StageProgress {
    plans: number
    planning_approved: number
    result_open: number
    result_pending: number
    result_approved: number
    result_rejected: number
    result_locked: number
}

/** The palette a status chip may take, shared by every IDP status surface. */
export type Tone = 'slate' | 'amber' | 'emerald' | 'red' | 'sky' | 'primary'

// --- The approver's desk ----------------------------------------------------

/** One program as a request on the desk shows it. */
export interface InboxPlan {
    id: number
    development_model: string | null
    competency_type: string
    competency_name: string
    development_program: string
    review_tools: string | null
    expected_outcome: string | null
    target: number | null
    uom: string | null
    time_frame_start: string | null
    time_frame_end: string | null
    realization_date: string | null
    achievement: number | null
    result_evidence: string | null
}

/** Plan fields compared between two rounds of the same request. */
export type InboxPlanField = Exclude<keyof InboxPlan, 'id'>

/** How a request differs from the round before it, and how that round ended. */
export interface InboxPrevious {
    approval_id: number
    submitted_at: string | null
    status: 'pending' | 'approved' | 'rejected'
    rejected: { level: number; name: string | null; note: string | null; at: string | null } | null
    /** Null when either round has no snapshot to compare. */
    diff: {
        added: number[]
        removed: InboxPlan[]
        /** plan id => field => [before, after] */
        changed: Record<number, Partial<Record<InboxPlanField, [unknown, unknown]>>>
    } | null
}

/**
 * One request on the approver's desk, in the one shape both of its surfaces
 * read — the pending list and the decision log.
 *
 * A PLANNING request covers a whole package's plan set; a RESULT request covers
 * exactly one program. Everything after `plans` belongs to one surface only.
 */
export interface InboxRequest {
    /** Identifies the ROW: one request appears twice when the same person sits on two of its layers. */
    step_id: number
    approval_id: number
    stage: 'planning' | 'result'
    /** The viewer's own layer on this chain. */
    level: number
    total_levels: number
    owner_id: string
    owner_name: string
    submitted_at: string | null
    package: { id: number; name: string } | null
    title: string | null
    /** As submitted — frozen at submission. */
    plans: InboxPlan[]
    /** False for a request that predates snapshots: `plans` are then the live rows. */
    frozen: boolean
    /** The round before this one for the same subject, or null on a first round. */
    previous: InboxPrevious | null

    // Pending rows — whether this layer's turn has come, and who holds it while
    // it has not.
    can_act?: boolean
    awaiting_level?: number | null
    awaiting_name?: string | null

    // History rows — what this person decided, and what became of it.
    decision?: 'approved' | 'rejected'
    decided_at?: string | null
    note?: string | null
    auto?: boolean
    outcome?: 'pending' | 'approved' | 'rejected'

    /** The whole chain — on both surfaces, so a card always shows where it sits. */
    chain?: ApprovalInfo
}
