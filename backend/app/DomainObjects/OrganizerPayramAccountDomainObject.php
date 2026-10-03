<?php

namespace HiEvents\DomainObjects;

/**
 * An organizer's PayRam merchant account.
 *
 * Deliberately thin: it holds the merchant identity and the gateway's own
 * verdict on whether a payout wallet is attached. Wallet *details* are not
 * ours to store — the organizer configures them in the PayRam console, and
 * anything we kept here would be a claim we cannot keep.
 */
class OrganizerPayramAccountDomainObject extends Generated\OrganizerPayramAccountDomainObjectAbstract {}
