function createRequestGeneration() {
	let generation = 0;
	let destroyed = false;
	let channels = Object.create(null);
	return {
		next(channel, identity) {
			channels[channel] = (channels[channel] || 0) + 1;
			return { channel, sequence: channels[channel], generation, identity: String(identity || '') };
		},
		isCurrent(ticket, identity) {
			return !destroyed && ticket.generation === generation
				&& channels[ticket.channel] === ticket.sequence
				&& ticket.identity === String(identity || '');
		},
		invalidate(channel) {
			channels[channel] = (channels[channel] || 0) + 1;
		},
		invalidateAll() {
			generation += 1;
			channels = Object.create(null);
		},
		destroy() {
			destroyed = true;
			generation += 1;
			channels = Object.create(null);
		}
	};
}

module.exports = { createRequestGeneration };
