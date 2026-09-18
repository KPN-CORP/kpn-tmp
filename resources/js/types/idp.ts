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
    time_frame_start: string | null
    time_frame_end: string | null
    realization_date: string | null
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

/**
 * One development-model cycle in the picker. The whole IDP screen shows exactly
 * one of these at a time; only the ACTIVE one can still be written in.
 */
export interface PackageOption {
    id: number
    name: string
    start_date: string | null
    end_date: string | null
    is_active: boolean
    /**
     * How many of this employee's plans sit in this cycle — null on a screen
     * that reports on many employees at once and so has no count to show.
     */
    plans: number | null
}

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
